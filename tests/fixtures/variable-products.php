<?php
/** Create a disposable variable product with a wildcard variation and shared stock. */
$parent = new WC_Product_Variable();
$parent->set_name( 'QuoteFlow options fixture' );
$parent->set_manage_stock( true );
$parent->set_stock_quantity( 6 );
$attributes = array();
foreach ( array( 'Color' => array( 'Red', 'Blue' ), 'Size' => array( 'Small', 'Large' ) ) as $name => $options ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( $name );
	$attribute->set_options( $options );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$attributes[] = $attribute;
}
$parent->set_attributes( $attributes );
$parent->save();
$ids = array();
foreach ( array( array( 'color' => 'Red', 'size' => '' ), array( 'color' => 'Blue', 'size' => 'Large' ) ) as $values ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_attributes( $values );
	$variation->set_virtual( true );
	$variation->set_regular_price( '25.00' );
	$variation->save();
	$ids[] = $variation->get_id();
}
WC_Product_Variable::sync( $parent->get_id() );
echo wp_json_encode( array( 'parent' => $parent->get_id(), 'red' => $ids[0], 'blue' => $ids[1] ) );
