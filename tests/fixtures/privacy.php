<?php
// Fictional data for the disposable store only.
use Latse\QuoteFlow\Offers;
use Latse\QuoteFlow\Requests;

global $wpdb;
$email = 'privacy-' . wp_generate_uuid4() . '@example.test';
$p = new WC_Product_Simple();
$p->set_name('Privacy fixture');
$p->set_regular_price('20.00');
$p->set_virtual(true);
$p->save();
$make_request = static function () use ($p, $email, $wpdb): int {
    $uuid = wp_generate_uuid4();
    Requests::submit($uuid, $p->get_id(), 1, $email, 'Private buyer note <literal>', $uuid);
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id = %s", $uuid));
};
$ids = [];
for ($i = 0; $i < 25; $i++) {
    $ids[] = $make_request();
}
// Legacy rows continue to export and erase without an items snapshot.
$wpdb->update($wpdb->prefix . 'qf_requests', ['items_json' => null], ['id' => $ids[0]]);
$tokens = [];
$linked = [];
foreach (['canceled', 'rejected', 'expired', 'active', 'converted'] as $state) {
    $id = $make_request();
    $token = Requests::respond($id, '15.00');
    $tokens[$state] = $token;
    $linked[$state] = $id;
    if (in_array($state, ['canceled', 'rejected'], true)) {
        Offers::close($token, $state);
    } elseif ($state === 'expired') {
        $wpdb->update($wpdb->prefix . 'qf_offers', ['expires_at' => '2000-01-01 00:00:00'], ['request_id' => $id]);
    } elseif ($state === 'converted') {
        $order = Offers::accept($token);
    }
}
$tokens['creating'] = Offers::create($p->get_id(), 1, '15.00', $email);
$wpdb->update($wpdb->prefix . 'qf_offers', ['state' => 'creating', 'accepted_at' => gmdate('Y-m-d H:i:s')], ['id' => Offers::find($tokens['creating'])->id]);
$tokens['sending'] = Offers::create($p->get_id(), 1, '15.00', $email);
Offers::close($tokens['sending'], 'canceled');
$wpdb->update($wpdb->prefix . 'qf_offers', ['email_state' => 'sending'], ['id' => Offers::find($tokens['sending'])->id]);
$foreign = Offers::create($p->get_id(), 1, '15.00', 'other-' . wp_generate_uuid4() . '@example.test');
Offers::close($foreign, 'canceled');
// A physical order supplies a complete accepted address for export.
$p->set_virtual(false);
$p->save();
$tokens['physical'] = Offers::create($p->get_id(), 1, '15.00', $email, 0, null, '2.00', 'US');
$physical = Offers::accept($tokens['physical'], [
    'first_name' => 'Ana', 'last_name' => 'Example', 'country' => 'US', 'address_1' => '123 Privacy Street',
    'address_2' => 'Unit 4', 'company' => 'Example Ltd', 'city' => 'Beverly Hills', 'state' => 'CA', 'postcode' => '90210'
]);
// Restore the virtual product so the earlier virtual order remains payable.
$p->set_virtual(true);
$p->save();
echo wp_json_encode(['email' => $email, 'tokens' => $tokens, 'linked' => $linked, 'unanswered' => $ids, 'foreign' => $foreign, 'order' => $order->get_id(), 'physical_order' => $physical->get_id()]);
