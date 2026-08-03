{{--
    Order receipt. Tables and inline styles throughout, deliberately: mail clients
    strip <style> blocks and have no opinion about flexbox. Brand palette matches
    the storefront — cream #f6f1e7, ink #101010, orange #f97b0c.
--}}
@php
    $peso = fn ($amount) => '₱'.number_format((int) $amount);
    $addressLines = array_filter([
        $order->street,
        $order->barangay,
        trim($order->city.', '.$order->province),
        trim(($order->region ?? '').' '.($order->postal ?? '')),
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order {{ $order->order_number }}</title>
</head>
<body style="margin:0; padding:0; background:#f6f1e7; color:#101010; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f1e7; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%; max-width:560px; background:#f6f1e7; border:2px solid #101010;">

                <tr>
                    <td style="background:#101010; color:#f6f1e7; padding:18px 20px; font-size:20px; font-weight:800; letter-spacing:2px;">
                        REEFER MNL
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 20px 8px;">
                        <p style="margin:0 0 6px; font-size:26px; font-weight:800; line-height:1.15; text-transform:uppercase;">
                            We got it.
                        </p>
                        <p style="margin:0; font-size:15px; line-height:1.5; color:#6b6357;">
                            Thanks, {{ $order->ship_to_name }}. Your order is in and we are packing it.
                            Keep this for your records.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px solid #101010; background:#f97b0c;">
                            <tr>
                                <td style="padding:12px 14px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
                                    Order number
                                </td>
                                <td align="right" style="padding:12px 14px; font-size:16px; font-weight:800;">
                                    {{ $order->order_number }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 20px 0;">
                        <p style="margin:0 0 8px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
                            What you bought
                        </p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px;">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td style="padding:10px 0; border-bottom:1px solid #d9d1c2; vertical-align:top;">
                                        <span style="font-weight:700;">{{ $item->name }}</span><br>
                                        <span style="color:#6b6357; font-size:13px;">Size {{ $item->size }} &middot; Qty {{ $item->qty }}</span>
                                    </td>
                                    <td align="right" style="padding:10px 0; border-bottom:1px solid #d9d1c2; vertical-align:top; font-weight:700; white-space:nowrap;">
                                        {{ $peso($item->line_total) }}
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            <tr>
                                <td style="padding:4px 0; color:#6b6357;">Subtotal</td>
                                <td align="right" style="padding:4px 0;">{{ $peso($order->subtotal) }}</td>
                            </tr>
                            @if ($order->discount_amount > 0)
                                <tr>
                                    <td style="padding:4px 0; color:#6b6357;">
                                        Discount
                                        @if ($order->discount_code)
                                            <span style="font-weight:700; color:#101010;">({{ $order->discount_code }})</span>
                                        @endif
                                    </td>
                                    <td align="right" style="padding:4px 0; font-weight:700;">&minus;{{ $peso($order->discount_amount) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:4px 0; color:#6b6357;">Shipping &middot; {{ $order->shipping_method_label }}</td>
                                <td align="right" style="padding:4px 0;">
                                    {{ $order->shipping_fee > 0 ? $peso($order->shipping_fee) : 'FREE' }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:12px 0 0; border-top:2px solid #101010; font-size:16px; font-weight:800; text-transform:uppercase;">Total</td>
                                <td align="right" style="padding:12px 0 0; border-top:2px solid #101010; font-size:16px; font-weight:800;">{{ $peso($order->total) }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="50%" style="vertical-align:top; padding-right:8px;">
                                    <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">Shipping to</p>
                                    <p style="margin:0; font-size:14px; line-height:1.55; color:#101010;">
                                        <span style="font-weight:700;">{{ $order->ship_to_name }}</span><br>
                                        @foreach ($addressLines as $line)
                                            {{ $line }}<br>
                                        @endforeach
                                        <span style="color:#6b6357;">{{ $order->phone }}</span>
                                    </p>
                                </td>
                                <td width="50%" style="vertical-align:top; padding-left:8px;">
                                    <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">Payment</p>
                                    <p style="margin:0; font-size:14px; line-height:1.55;">
                                        <span style="font-weight:700;">{{ $order->payment_method_label }}</span><br>
                                        <span style="color:#6b6357;">
                                            {{ $order->payment_status === 'paid' ? 'Paid in full.' : 'Due on delivery. Have it ready.' }}
                                        </span>
                                        @if ($order->payment_ref)
                                            <br><span style="color:#6b6357; font-size:13px;">Ref {{ $order->payment_ref }}</span>
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 20px 26px;">
                        <p style="margin:0; font-size:13px; line-height:1.6; color:#6b6357;">
                            We will email you again the moment it ships. Anything looks wrong? Reply to this
                            message and we will sort it out.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background:#101010; color:#f6f1e7; padding:14px 20px; font-size:11px; letter-spacing:1.5px; text-transform:uppercase;">
                        Reefer MNL &middot; Salt, asphalt, and good cotton.
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
