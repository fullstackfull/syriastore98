<?php

namespace App\Services;

use App\Models\Order;

class ChatwootOrderVariableService
{
    public function resolve(
        Order $order,
        array $parameters
    ): array {

    $orderDetails = Order::order_detail($order->id);

        $values = [];

        foreach ($parameters as $position => $variable) {

            $values[(string) $position] =
                $this->resolveVariable(
                    $variable,
                    $order,
                    $orderDetails
                );
        }

        return $values;
    }


    protected function resolveVariable(
        string $variable,
        Order $order,
        array $orderDetails
    ): string {

        switch ($variable) {

            case 'customer_name':

                return trim((string) (
                    $orderDetails['billing_informations']['name']
                    ?? $orderDetails['delivery_informations']['name']
                    ?? ''
                ));


            case 'customer_phone':

                return trim((string) (
                    $orderDetails['billing_informations']['phone']
                    ?? $orderDetails['delivery_informations']['phone']
                    ?? ''
                ));


            case 'order_number':

                return (string) (
                    $order->product_order_id
                    ?? $order->id
                );


            case 'order_status':

                return $this->getOrderStatus($order);


            case 'order_total':

                return (string) (
                    $orderDetails['final_price']
                    ?? $order->final_price
                    ?? ''
                );


            case 'payment_status':

                return (string) (
                    $order->payment_status
                    ?? ''
                );


            case 'payment_type':

                return (string) (
                    $order->payment_type
                    ?? ''
                );


            case 'order_date':

                if ($order->order_date) {
                    return (string) $order->order_date;
                }

                if ($order->created_at) {
                    return $order->created_at->format('Y-m-d H:i');
                }

                return '';


            case 'delivery_date':

                return (string) (
                    $order->delivery_date
                    ?? ''
                );


            case 'store_name':

                if (
                    method_exists($order, 'store') &&
                    $order->store
                ) {
                    return (string) $order->store->name;
                }

                return '';


            default:

                return '';
        }
    }


    protected function getOrderStatus(
        Order $order
    ): string {

        switch ((int) $order->delivered_status) {

            case 0:
                return 'Pending';

            case 1:
                return 'Delivered';

            case 2:
                return 'Cancelled';

            case 3:

                if ((int) $order->return_status === 1) {
                    return 'Return request processing';
                }

                if ((int) $order->return_status === 2) {
                    return 'Returned';
                }

                if ((int) $order->return_status === 3) {
                    return 'Return cancelled';
                }

                return 'Return';

            case 4:
                return 'Confirmed';

            case 5:
                return 'Picked Up';

            case 6:
                return 'Shipped';

            default:
                return 'Unknown';
        }
    }



    
}