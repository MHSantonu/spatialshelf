import { SegmentationRenderer } from '@meta-sam/graphics';
import {
  parseVideoStream,
  type ResponsesEvent,
  type VideoSegmentationResult,
} from '@meta-sam/parser';
import { createMediaPlayer, type IMediaPlayer } from '@meta-sam/video';

/** Lane identity for the replayed stream; the parser requires it on every text event. */
const LANE = { item_id: 'overlay-1', output_index: 0, content_index: 0 } as const;

/**
 * Replay saved SAM output as stream events: one delta per line, then a done
 * event carrying the exact accumulated text (the parser throws if the lane
 * is not finalized or the text conflicts), then completion.
 */
async function* responseEvents(text: string): AsyncIterable<ResponsesEvent> {
  const body = text.endsWith('\n') ? text.slice(0, -1) : text;
  let full = '';
  for (const line of body.split('\n')) {
    const delta = `${line}\n`;
    full += delta;
    yield { type: 'response.output_text.delta', ...LANE, delta };
  }
  yield { type: 'response.output_text.done', ...LANE, text: full };
  yield { type: 'response.completed' };
}

const root = document.getElementById('scan-overlay-root');
if (root instanceof HTMLElement) {
  void initOverlay(root);
}

async function initOverlay(root: HTMLElement): Promise<void> {
  const canvas = root.querySelector<HTMLCanvasElement>('#scan-overlay-canvas');
  const playButton =
    root.querySelector<HTMLButtonElement>('#scan-overlay-play');
  const loopToggle =
    root.querySelector<HTMLInputElement>('#scan-overlay-loop');
  const status = root.querySelector('#scan-overlay-status');
  const clipUrl = root.dataset.clipUrl;
  const samUrl = root.dataset.samUrl;
  const phrase = root.dataset.phrase ?? 'product';
  if (
    canvas === null ||
    playButton === null ||
    loopToggle === null ||
    status === null ||
    clipUrl === undefined ||
    samUrl === undefined
  ) {
    throw new Error('Watch page is missing its player elements.');
  }
  const setStatus = (message: string): void => {
    status.textContent = message;
  };

  const refs: { player?: IMediaPlayer; renderer?: SegmentationRenderer } = {};
  document.addEventListener(
    'livewire:navigating',
    () => {
      refs.player?.dispose();
      refs.renderer?.dispose();
    },
    { once: true },
  );

  let pixelRatio = window.devicePixelRatio || 1;

  try {
    const samResponse = await fetch(samUrl);
    if (!samResponse.ok) {
      throw new Error(`overlay data failed to load (${samResponse.status})`);
    }
    const rawText = await samResponse.text();
    if (rawText.trim() === '') {
      throw new Error('this scan has no overlay data');
    }
    const result: VideoSegmentationResult = await parseVideoStream(
      responseEvents(rawText),
    ).finalResult;

    const renderer = new SegmentationRenderer({ boxLabels: true });
    await renderer.update(result);
    refs.renderer = renderer;

    const player = createMediaPlayer(canvas);
    refs.player = player;
    player.setCustomRender((context) => {
      renderer.renderVideoFrame(context, {
        fit: 'contain',
        devicePixelRatio: () => pixelRatio,
        boxLabel: phrase,
      });
    });

    const resize = (width: number, height: number): void => {
      const cssWidth = root.clientWidth;
      const cssHeight = Math.max(1, Math.round((cssWidth * height) / width));
      canvas.style.width = `${cssWidth}px`;
      canvas.style.height = `${cssHeight}px`;
      canvas.width = Math.round(cssWidth * pixelRatio);
      canvas.height = Math.round(cssHeight * pixelRatio);
    };
    player.on('loadedmetadata', ({ width, height }) => {
      pixelRatio = window.devicePixelRatio || 1;
      resize(width, height);
      void player.forceRender().catch(() => undefined);
    });

    player.on('error', ({ error }) => {
      setStatus(`Playback failed: ${error.message}`);
    });
    const syncPlayButton = (): void => {
      playButton.textContent = player.paused ? 'Play' : 'Pause';
    };
    player.on('play', syncPlayButton);
    player.on('pause', syncPlayButton);
    player.on('ended', syncPlayButton);
    playButton.addEventListener('click', () => {
      if (player.paused) {
        void player.play().catch((error: unknown) => {
          const detail =
            error instanceof Error ? error.message : String(error);
          setStatus(`Could not play: ${detail}`);
        });
      } else {
        player.pause();
      }
    });

    player.loop = loopToggle.checked;
    loopToggle.addEventListener('change', () => {
      player.loop = loopToggle.checked;
    });

    await player.open(clipUrl);
    playButton.disabled = false;
    setStatus(`Tracking "${phrase}" — press Play.`);
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error);
    setStatus(`Could not load the overlay: ${detail}`);
  }
}
