<?php

namespace Tests\Feature;

use App\Enums\ConversationState;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $shop = Shop::factory()->create();
        $order = Order::factory()->create(['shop_id' => $shop->id]);

        $this->get(route('orders'))->assertRedirect(route('login'));
        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
        $this->get(route('orders.input', $order))->assertRedirect(route('login'));
        $this->post(route('orders.packed', $order))->assertRedirect(route('login'));
    }

    public function test_list_shows_newest_first_with_badge_and_row_fields(): void
    {
        $shop = Shop::factory()->create();
        $rahim = Customer::factory()->create(['shop_id' => $shop->id, 'name' => 'Rahim Uddin', 'wa_id' => '8801711111111']);
        $old = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $rahim->id, 'status' => OrderStatus::Packed,
            'source_type' => 'text', 'context' => ['channel' => 'whatsapp', 'input_text' => 'old list'], 'total' => 100,
        ]);
        $old->lines()->delete();
        $old->lines()->create(['product_id' => null, 'requested_text' => 'salt', 'qty' => 1, 'status' => 'not_found']);
        $new = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => null, 'status' => OrderStatus::Confirmed,
            'source_type' => 'text', 'context' => ['channel' => 'try', 'input_text' => 'new list'], 'total' => 900,
        ]);
        $new->lines()->delete();
        $oil = Product::factory()->create(['shop_id' => $shop->id, 'price' => 300]);
        $new->lines()->create([
            'product_id' => $oil->id, 'requested_text' => 'oil', 'qty' => 3,
            'unit_price' => 300, 'line_total' => 900, 'status' => 'matched',
        ]);
        // The factory's afterCreating hook overwrote the explicit total.
        $new->update(['total' => 900]);
        Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $rahim->id, 'status' => OrderStatus::Confirmed,
            'source_type' => 'text', 'context' => ['channel' => 'whatsapp'], 'total' => 50,
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('orders'));

        $response->assertOk()
            ->assertSee('wire:poll.5s', false)
            ->assertSee('2 new')
            ->assertSee('#'.$new->id)
            ->assertSee('Demo')
            ->assertSee('aria-label="Try demo order"', false)
            ->assertSee('aria-label="WhatsApp order"', false)
            ->assertSee('3 items')
            ->assertSee('৳900', false)
            ->assertSee('Confirmed')
            ->assertSee('Rahim Uddin')
            ->assertSee('<time', false)
            ->assertSee(route('orders.show', $new, absolute: false));
        $content = $response->getContent();
        $this->assertTrue(strpos($content, '#'.$new->id) < strpos($content, '#'.$old->id));
    }

    public function test_list_hides_badge_when_nothing_is_confirmed(): void
    {
        $shop = Shop::factory()->create();
        Order::factory()->create(['shop_id' => $shop->id, 'status' => OrderStatus::Packed]);
        $this->actingAs(User::factory()->create());

        $this->get(route('orders'))->assertOk()->assertDontSee(' new</span>', false);
    }

    public function test_detail_shows_typed_text_as_original_input(): void
    {
        $shop = Shop::factory()->create();
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'source_type' => 'text',
            'context' => ['channel' => 'whatsapp', 'input_text' => '2 ta Tang, 1 ta salt'],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('Order #'.$order->id)
            ->assertSee('Original input')
            ->assertSee('2 ta Tang, 1 ta salt')
            ->assertSee('Picking route');
    }

    public function test_detail_shows_photo_thumbnail(): void
    {
        Storage::fake('shared');
        Storage::disk('shared')->put('wa-media/list1.jpg', 'fake-jpg');
        $shop = Shop::factory()->create();
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'source_type' => 'photo',
            'raw_input_path' => 'wa-media/list1.jpg',
            'context' => ['channel' => 'whatsapp', 'input_text' => 'amar list'],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee(route('orders.input', $order, absolute: false))
            ->assertSee("Customer's handwritten list photo", false)
            ->assertSee('amar list');
    }

    public function test_detail_shows_voice_player_with_transcript(): void
    {
        Storage::fake('shared');
        Storage::disk('shared')->put('wa-media/note1.ogg', 'fake-ogg');
        $shop = Shop::factory()->create();
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'source_type' => 'voice',
            'raw_input_path' => 'wa-media/note1.ogg', 'transcript' => 'Tang two piece',
            'context' => ['channel' => 'whatsapp'],
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('orders.show', $order))->assertOk()
            ->assertSee('<audio', false)
            ->assertSee(route('orders.input', $order, absolute: false))
            ->assertSee('Tang two piece');
    }

    public function test_picking_route_is_sorted_by_shelf_with_checkboxes(): void
    {
        Storage::fake('shared');
        Storage::disk('shared')->put('scans/1/crops/oil.jpg', 'fake');
        $shop = Shop::factory()->create();
        $row2second = Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Row2 Second', 'price' => 100, 'shelf_row' => 2, 'shelf_order' => 5,
        ]);
        $row1 = Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Row1 First', 'price' => 50, 'shelf_row' => 1, 'shelf_order' => 3,
        ]);
        $row2first = Product::factory()->create([
            'shop_id' => $shop->id, 'name' => 'Row2 First', 'price' => 75, 'shelf_row' => 2, 'shelf_order' => 1,
        ]);
        $image = ProductImage::create(['product_id' => $row1->id, 'crop_path' => 'scans/1/crops/oil.jpg']);
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'source_type' => 'text',
            'context' => ['channel' => 'whatsapp', 'input_text' => 'list'], 'total' => 225,
        ]);
        $order->lines()->delete();
        $lineA = $order->lines()->create([
            'product_id' => $row2second->id, 'requested_text' => 'second', 'qty' => 2,
            'unit_price' => 100, 'line_total' => 200, 'status' => 'matched',
        ]);
        $order->lines()->create([
            'product_id' => $row1->id, 'requested_text' => 'first', 'qty' => 1,
            'unit_price' => 50, 'line_total' => 50, 'status' => 'matched',
        ]);
        $order->lines()->create([
            'product_id' => $row2first->id, 'requested_text' => 'middle', 'qty' => 3,
            'unit_price' => 75, 'line_total' => 225, 'status' => 'matched',
        ]);
        $order->lines()->create([
            'product_id' => null, 'requested_text' => 'mystery powder', 'qty' => 1, 'status' => 'not_found',
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('orders.show', $order));

        $response->assertOk()
            ->assertSee('Row 1 · #3')
            ->assertSee('Row 2 · #1')
            ->assertSee('Row 2 · #5')
            ->assertSee('0 of 3 picked')
            ->assertSee('wire:model="picked.'.$lineA->id.'"', false)
            ->assertSee('×2')
            ->assertSee(route('product-images.show', $image, absolute: false))
            ->assertSee('Not on the shelf')
            ->assertSee('mystery powder');
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Row1 First') < strpos($content, 'Row2 First'));
        $this->assertTrue(strpos($content, 'Row2 First') < strpos($content, 'Row2 Second'));
    }

    public function test_packing_inside_the_window_notifies_the_customer(): void
    {
        Http::fake();
        config([
            'spatialshelf.wa_access_token' => 'wa-token',
            'spatialshelf.wa_phone_number_id' => 'phone-123',
        ]);
        $shop = Shop::factory()->create(['name' => 'Rahim Store']);
        $customer = Customer::factory()->create(['shop_id' => $shop->id, 'name' => 'Rahim', 'wa_id' => '8801711111111']);
        Conversation::create([
            'customer_id' => $customer->id, 'state' => ConversationState::Idle,
            'last_inbound_at' => now()->subHours(2),
        ]);
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Confirmed,
            'context' => ['channel' => 'whatsapp'],
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->from(route('orders.show', $order))
            ->followingRedirects()
            ->post(route('orders.packed', $order));

        $response->assertOk()
            ->assertSee('Packed and ready for pickup.')
            ->assertSee('Packed. The customer was notified on WhatsApp.');
        $this->assertSame(OrderStatus::Packed, $order->fresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'phone-123/messages')
            && $request['to'] === '8801711111111'
            && $request['text']['body'] === "Your order #{$order->id} is packed and ready for pickup at Rahim Store.");
    }

    public function test_packing_after_the_window_skips_sending_and_shows_a_notice(): void
    {
        Http::fake();
        config([
            'spatialshelf.wa_access_token' => 'wa-token',
            'spatialshelf.wa_phone_number_id' => 'phone-123',
        ]);
        $shop = Shop::factory()->create();
        $customer = Customer::factory()->create(['shop_id' => $shop->id, 'name' => 'Rahim', 'wa_id' => '8801711111111']);
        Conversation::create([
            'customer_id' => $customer->id, 'state' => ConversationState::Idle,
            'last_inbound_at' => now()->subHours(25),
        ]);
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Confirmed,
            'context' => ['channel' => 'whatsapp'],
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->from(route('orders.show', $order))
            ->followingRedirects()
            ->post(route('orders.packed', $order));

        $response->assertOk()->assertSee('older than 24 hours');
        $this->assertSame(OrderStatus::Packed, $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_packing_without_a_conversation_skips_sending(): void
    {
        Http::fake();
        config([
            'spatialshelf.wa_access_token' => 'wa-token',
            'spatialshelf.wa_phone_number_id' => 'phone-123',
        ]);
        $shop = Shop::factory()->create();
        $customer = Customer::factory()->create(['shop_id' => $shop->id, 'wa_id' => '8801711111111']);
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Confirmed,
            'context' => ['channel' => 'whatsapp'],
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->from(route('orders.show', $order))
            ->followingRedirects()
            ->post(route('orders.packed', $order));

        $response->assertOk()->assertSee('no recent WhatsApp conversation');
        $this->assertSame(OrderStatus::Packed, $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_packing_a_try_order_sends_no_message(): void
    {
        Http::fake();
        config([
            'spatialshelf.wa_access_token' => 'wa-token',
            'spatialshelf.wa_phone_number_id' => 'phone-123',
        ]);
        $shop = Shop::factory()->create();
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'customer_id' => null, 'status' => OrderStatus::Confirmed,
            'context' => ['channel' => 'try'],
        ]);
        $this->actingAs(User::factory()->create());

        $response = $this->from(route('orders.show', $order))
            ->followingRedirects()
            ->post(route('orders.packed', $order));

        $response->assertOk()
            ->assertSee('Packed and ready for pickup.')
            ->assertDontSee('notified');
        $this->assertSame(OrderStatus::Packed, $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_packing_a_draft_fails_without_changing_status(): void
    {
        $shop = Shop::factory()->create();
        $order = Order::factory()->create(['shop_id' => $shop->id, 'status' => OrderStatus::Draft]);
        $this->actingAs(User::factory()->create());

        $response = $this->from(route('orders.show', $order))
            ->followingRedirects()
            ->post(route('orders.packed', $order));

        $response->assertOk()->assertSee('Only confirmed orders can be packed');
        $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
    }

    public function test_order_input_route_serves_and_404s(): void
    {
        Storage::fake('shared');
        $shop = Shop::factory()->create();
        Storage::disk('shared')->put('wa-media/list1.jpg', 'fake-jpg');
        $order = Order::factory()->create([
            'shop_id' => $shop->id, 'source_type' => 'photo', 'raw_input_path' => 'wa-media/list1.jpg',
        ]);
        $missing = Order::factory()->create([
            'shop_id' => $shop->id, 'source_type' => 'photo', 'raw_input_path' => 'wa-media/gone.jpg',
        ]);
        $noInput = Order::factory()->create(['shop_id' => $shop->id, 'source_type' => 'text']);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('orders.input', $order));
        $response->assertOk();
        $this->assertSame('fake-jpg', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get(route('orders.input', $missing))->assertNotFound();
        $this->get(route('orders.input', $noInput))->assertNotFound();
        $this->get(route('orders.show', 999999))->assertNotFound();
    }
}
