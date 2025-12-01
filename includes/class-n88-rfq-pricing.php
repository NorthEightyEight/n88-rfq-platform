<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Pricing Calculator
 * 
 * Handles instant pricing calculations for quotes including:
 * - Unit price calculation (labor + materials + overhead + margin)
 * - Total price calculation
 * - CBM (cubic meters) volume calculation
 * - Volume-based pricing rules
 * - Lead time estimation
 */
class N88_RFQ_Pricing {

    /**
     * Calculate unit price from cost components
     *
     * @param float $labor_cost Labor cost per unit
     * @param float $materials_cost Materials cost per unit
     * @param float $overhead_cost Overhead cost per unit
     * @param float $margin_percentage Margin percentage (e.g., 15.5 for 15.5%)
     * @return float Unit price
     */
    public static function calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage ) {
        $labor_cost = (float) $labor_cost;
        $materials_cost = (float) $materials_cost;
        $overhead_cost = (float) $overhead_cost;
        $margin_percentage = (float) $margin_percentage;

        // Total cost before margin
        $total_cost = $labor_cost + $materials_cost + $overhead_cost;

        // Apply margin
        $margin_amount = $total_cost * ( $margin_percentage / 100 );
        $unit_price = $total_cost + $margin_amount;

        return round( $unit_price, 2 );
    }

    /**
     * Calculate total price
     *
     * @param float $unit_price Unit price
     * @param int $quantity Quantity
     * @return float Total price
     */
    public static function calculate_total_price( $unit_price, $quantity ) {
        $unit_price = (float) $unit_price;
        $quantity = (int) $quantity;

        return round( $unit_price * $quantity, 2 );
    }

    /**
     * Calculate CBM (Cubic Meters) from dimensions in inches
     *
     * @param float $length_in Length in inches
     * @param float $depth_in Depth in inches
     * @param float $height_in Height in inches
     * @param int $quantity Quantity
     * @return float CBM volume
     */
    public static function calculate_cbm( $length_in, $depth_in, $height_in, $quantity = 1 ) {
        $length_in = (float) $length_in;
        $depth_in = (float) $depth_in;
        $height_in = (float) $height_in;
        $quantity = (int) $quantity;

        // Convert inches to meters (1 inch = 0.0254 meters)
        $length_m = $length_in * 0.0254;
        $depth_m = $depth_in * 0.0254;
        $height_m = $height_in * 0.0254;

        // Calculate volume in cubic meters
        $cbm_per_unit = $length_m * $depth_m * $height_m;
        $total_cbm = $cbm_per_unit * $quantity;

        return round( $total_cbm, 4 );
    }

    /**
     * Apply volume-based pricing rules
     *
     * @param float $base_unit_price Base unit price
     * @param float $total_cbm Total CBM volume
     * @param int $quantity Total quantity
     * @return array Array with adjusted_price and rules_applied
     */
    public static function apply_volume_rules( $base_unit_price, $total_cbm, $quantity ) {
        $base_unit_price = (float) $base_unit_price;
        $total_cbm = (float) $total_cbm;
        $quantity = (int) $quantity;

        $adjusted_price = $base_unit_price;
        $rules_applied = array();

        // Rule 1: Volume discount for large orders (CBM > 10)
        if ( $total_cbm > 10 ) {
            $discount_percentage = min( 15, ( $total_cbm - 10 ) * 0.5 ); // Max 15% discount
            $discount_amount = $base_unit_price * ( $discount_percentage / 100 );
            $adjusted_price = $base_unit_price - $discount_amount;
            $rules_applied[] = sprintf( 'Volume discount: %.1f%% (CBM: %.2f)', $discount_percentage, $total_cbm );
        }

        // Rule 2: Quantity discount for bulk orders (quantity >= 10)
        if ( $quantity >= 10 ) {
            $qty_discount = min( 10, ( $quantity - 10 ) * 0.5 ); // Max 10% discount
            $qty_discount_amount = $adjusted_price * ( $qty_discount / 100 );
            $adjusted_price = $adjusted_price - $qty_discount_amount;
            $rules_applied[] = sprintf( 'Bulk discount: %.1f%% (Qty: %d)', $qty_discount, $quantity );
        }

        // Rule 3: Small order surcharge (CBM < 1 and quantity < 5)
        if ( $total_cbm < 1 && $quantity < 5 ) {
            $surcharge_percentage = 10;
            $surcharge_amount = $adjusted_price * ( $surcharge_percentage / 100 );
            $adjusted_price = $adjusted_price + $surcharge_amount;
            $rules_applied[] = sprintf( 'Small order surcharge: %.1f%%', $surcharge_percentage );
        }

        return array(
            'adjusted_price' => round( $adjusted_price, 2 ),
            'rules_applied' => $rules_applied,
        );
    }

    /**
     * Calculate lead time based on quantity and complexity
     *
     * @param int $quantity Total quantity
     * @param float $total_cbm Total CBM volume
     * @param string $shipping_zone Shipping zone
     * @return string Lead time estimate
     */
    public static function calculate_lead_time( $quantity, $total_cbm, $shipping_zone = '' ) {
        $quantity = (int) $quantity;
        $total_cbm = (float) $total_cbm;

        // Base production time (weeks)
        $base_weeks = 2;

        // Adjust based on quantity
        if ( $quantity >= 50 ) {
            $base_weeks += 2; // Large orders take longer
        } elseif ( $quantity >= 20 ) {
            $base_weeks += 1;
        }

        // Adjust based on volume
        if ( $total_cbm > 20 ) {
            $base_weeks += 1; // Large items take longer
        }

        // Shipping time based on zone
        $shipping_weeks = 0;
        switch ( strtolower( $shipping_zone ) ) {
            case 'domestic':
            case 'local':
                $shipping_weeks = 1;
                break;
            case 'international':
            case 'overseas':
                $shipping_weeks = 3;
                break;
            case 'express':
                $shipping_weeks = 0.5;
                break;
            default:
                $shipping_weeks = 1;
        }

        $total_weeks = $base_weeks + $shipping_weeks;

        // Format as readable string
        if ( $total_weeks < 1 ) {
            return '3-5 days';
        } elseif ( $total_weeks == 1 ) {
            return '1 week';
        } elseif ( $total_weeks < 2 ) {
            return '1-2 weeks';
        } elseif ( $total_weeks < 4 ) {
            return round( $total_weeks ) . ' weeks';
        } else {
            return round( $total_weeks ) . '-' . ( round( $total_weeks ) + 1 ) . ' weeks';
        }
    }

    /**
     * Calculate pricing for all items in a project
     *
     * @param int $project_id Project ID
     * @param float $labor_cost Labor cost per unit
     * @param float $materials_cost Materials cost per unit
     * @param float $overhead_cost Overhead cost per unit
     * @param float $margin_percentage Margin percentage
     * @param string $shipping_zone Shipping zone
     * @return array Pricing summary
     */
    public static function calculate_project_pricing( $project_id, $labor_cost, $materials_cost, $overhead_cost, $margin_percentage, $shipping_zone ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        
        if ( ! $project ) {
            return false;
        }

        // Get project items
        $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
        $items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();

        if ( empty( $items ) ) {
            return false;
        }

        $total_quantity = 0;
        $total_cbm = 0;
        $item_pricing = array();

        foreach ( $items as $index => $item ) {
            $length = isset( $item['length_in'] ) ? (float) $item['length_in'] : ( isset( $item['dimensions']['length'] ) ? (float) $item['dimensions']['length'] : 0 );
            $depth = isset( $item['depth_in'] ) ? (float) $item['depth_in'] : ( isset( $item['dimensions']['depth'] ) ? (float) $item['dimensions']['depth'] : 0 );
            $height = isset( $item['height_in'] ) ? (float) $item['height_in'] : ( isset( $item['dimensions']['height'] ) ? (float) $item['dimensions']['height'] : 0 );
            $quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

            // Calculate CBM for this item
            $item_cbm = self::calculate_cbm( $length, $depth, $height, $quantity );
            $total_cbm += $item_cbm;
            $total_quantity += $quantity;

            // Calculate base unit price
            $base_unit_price = self::calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage );

            // Apply volume rules
            $volume_result = self::apply_volume_rules( $base_unit_price, $item_cbm, $quantity );
            $adjusted_unit_price = $volume_result['adjusted_price'];

            // Calculate item total
            $item_total = self::calculate_total_price( $adjusted_unit_price, $quantity );

            $item_pricing[] = array(
                'item_index' => $index,
                'quantity' => $quantity,
                'cbm' => $item_cbm,
                'base_unit_price' => $base_unit_price,
                'adjusted_unit_price' => $adjusted_unit_price,
                'item_total' => $item_total,
                'rules_applied' => $volume_result['rules_applied'],
            );
        }

        // Calculate overall totals
        $overall_unit_price = self::calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage );
        $overall_volume_result = self::apply_volume_rules( $overall_unit_price, $total_cbm, $total_quantity );
        $final_unit_price = $overall_volume_result['adjusted_price'];
        $grand_total = self::calculate_total_price( $final_unit_price, $total_quantity );

        // Calculate lead time
        $lead_time = self::calculate_lead_time( $total_quantity, $total_cbm, $shipping_zone );

        return array(
            'project_id' => $project_id,
            'labor_cost' => (float) $labor_cost,
            'materials_cost' => (float) $materials_cost,
            'overhead_cost' => (float) $overhead_cost,
            'margin_percentage' => (float) $margin_percentage,
            'shipping_zone' => $shipping_zone,
            'base_unit_price' => $overall_unit_price,
            'final_unit_price' => $final_unit_price,
            'total_quantity' => $total_quantity,
            'total_cbm' => round( $total_cbm, 4 ),
            'total_price' => $grand_total,
            'lead_time' => $lead_time,
            'volume_rules_applied' => $overall_volume_result['rules_applied'],
            'item_pricing' => $item_pricing,
        );
    }
}

