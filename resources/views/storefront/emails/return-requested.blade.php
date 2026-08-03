{{--
    Return acknowledgement. Tables and inline styles throughout, deliberately: mail
    clients strip <style> blocks and have no opinion about flexbox. Brand palette
    matches the storefront — cream #f6f1e7, ink #101010, orange #f97b0c.
--}}
@php
    $peso = fn ($amount) => '₱'.number_format((int) $amount);
    $order = $returnRequest->order;
    $windowDays = (int) config('reefer.returns.window_days');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return {{ $returnRequest->reference }}</title>
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
                            Send it back.
                        </p>
                        <p style="margin:0; font-size:15px; line-height:1.5; color:#6b6357;">
                            Thanks, {{ $order->ship_to_name }}. Your return on order {{ $order->order_number }} is logged.
                            We will reply with the drop-off details shortly.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px solid #101010; background:#f97b0c;">
                            <tr>
                                <td style="padding:12px 14px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
                                    Return reference
                                </td>
                                <td align="right" style="padding:12px 14px; font-size:16px; font-weight:800;">
                                    {{ $returnRequest->reference }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 20px 0;">
                        <p style="margin:0 0 8px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
                            What is coming back
                        </p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:14px;">
                            @foreach ($returnRequest->items as $item)
                                <tr>
                                    <td style="padding:10px 0; border-bottom:1px solid #d9d1c2; vertical-align:top;">
                                        <span style="font-weight:700;">{{ $item->orderItem?->name }}</span><br>
                                        <span style="color:#6b6357; font-size:13px;">Size {{ $item->orderItem?->size }} &middot; Qty {{ $item->qty }}</span>
                                    </td>
                                    <td align="right" style="padding:10px 0; border-bottom:1px solid #d9d1c2; vertical-align:top; font-weight:700; white-space:nowrap;">
                                        {{ $peso($item->lineTotal()) }}
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:12px 0 0; border-top:2px solid #101010; font-size:16px; font-weight:800; text-transform:uppercase;">
                                    Refund due
                                </td>
                                <td align="right" style="padding:12px 0 0; border-top:2px solid #101010; font-size:16px; font-weight:800;">
                                    {{ $peso($returnRequest->refundSubtotal()) }}
                                </td>
                            </tr>
                        </table>
                        {{-- Said out loud, because the amount above is not money in hand yet. --}}
                        <p style="margin:10px 0 0; font-size:13px; line-height:1.6; color:#6b6357;">
                            Refund is on the items only — shipping already paid is not included, and nothing moves
                            until we have the pieces back and checked.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:22px 20px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="50%" style="vertical-align:top; padding-right:8px;">
                                    <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">Reason</p>
                                    <p style="margin:0; font-size:14px; line-height:1.55;">
                                        <span style="font-weight:700;">{{ $returnRequest->reason_label }}</span>
                                        @if ($returnRequest->note)
                                            <br><span style="color:#6b6357;">{{ $returnRequest->note }}</span>
                                        @endif
                                    </p>
                                </td>
                                <td width="50%" style="vertical-align:top; padding-left:8px;">
                                    <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">Status</p>
                                    <p style="margin:0; font-size:14px; line-height:1.55;">
                                        <span style="font-weight:700; text-transform:capitalize;">{{ $returnRequest->status }}</span><br>
                                        <span style="color:#6b6357;">Logged {{ optional($returnRequest->requested_at)->format('M j, Y') }}</span>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 20px 26px;">
                        <p style="margin:0; font-size:13px; line-height:1.6; color:#6b6357;">
                            Keep the pieces unworn, unwashed and tagged — that is the whole of our
                            {{ $windowDays }}-day policy. Changed your mind about sending them back? Cancel the return
                            from your account while it is still pending, or just reply to this message.
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
