<?php
use Latse\QuoteFlow\Offers;
use Latse\QuoteFlow\Requests;

global $wpdb;
$email = 'inbox-' . wp_generate_uuid4() . '@example.test';
$exact_email = 'exact-' . wp_generate_uuid4() . '@example.test';
$p = new WC_Product_Simple();
$p->set_name('Inbox fixture');
$p->set_regular_price('20');
$p->set_virtual(true);
$p->save();
$make = static function (string $buyer) use ($p, $wpdb): int {
    $uuid = wp_generate_uuid4();
    Requests::submit($uuid, $p->get_id(), 1, $buyer, 'Inbox fixture note', $uuid);
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s", $uuid));
};
$ids = ['pending' => [], 'offered' => [], 'expired' => [], 'creating' => [], 'converted' => [], 'rejected' => [], 'canceled' => []];
$orders = [];
foreach (['pending' => 21, 'offered' => 2, 'expired' => 2, 'creating' => 1, 'converted' => 2, 'rejected' => 1, 'canceled' => 1] as $state => $count) {
    for ($i = 0; $i < $count; $i++) {
        $id = $make($email);
        $ids[$state][] = $id;
        if ($state === 'pending') { continue; }
        $token = Requests::respond($id, '15');
        $offer = Offers::find($token);
        if ($state === 'converted') {
            $order = Offers::accept($token);
            $orders[$id] = ['id' => $order->get_id(), 'url' => $order->get_edit_order_url()];
        } elseif (in_array($state, ['rejected', 'canceled'], true)) {
            Offers::close($token, $state);
        } elseif ($state === 'creating') {
            $wpdb->update($wpdb->prefix.'qf_offers', ['state' => 'creating', 'accepted_at' => gmdate('Y-m-d H:i:s')], ['id' => $offer->id]);
        }
        if (in_array($state, ['expired', 'converted', 'rejected', 'canceled'], true)) {
            $wpdb->update($wpdb->prefix.'qf_offers', ['expires_at' => '2000-01-01 00:00:00'], ['id' => $offer->id]);
        }
    }
}
$exact = [];
for ($i = 0; $i < 20; $i++) { $exact[] = $make($exact_email); }
echo wp_json_encode(['email' => $email, 'ids' => $ids, 'orders' => $orders, 'exact_email' => $exact_email, 'exact' => $exact]);
