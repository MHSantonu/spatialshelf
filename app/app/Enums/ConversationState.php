<?php

namespace App\Enums;

enum ConversationState: string
{
    case Idle = 'idle';
    case AwaitingClarification = 'awaiting_clarification';
    case AwaitingConfirmation = 'awaiting_confirmation';
}
