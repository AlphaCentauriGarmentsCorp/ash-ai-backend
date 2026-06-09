{{-- resources/views/admin/quotations/pdf.blade.php --}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation #{{ $quotation->quotation_id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', 'Poppins', Arial, sans-serif;
            background: white;
            padding: 25px;
            font-size: 8px;
            line-height: 1.2;
            color: #333;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #001c34;
        }

        .company-info h1 {
            color: #001c34;
            font-size: 14px;
            margin-bottom: 3px;
        }

        .company-info p {
            color: #666;
            font-size: 6px;
            margin: 1px 0;
        }

        .quotation-info {
            text-align: right;
        }

        .quotation-info .quotation-number {
            font-size: 10px;
            font-weight: bold;
            color: #001c34;
            margin-bottom: 2px;
        }

        .quotation-info div {
            font-size: 7px;
            margin: 1px 0;
        }

        .quotation-info .status {
            display: inline-block;
            padding: 2px 6px;
            background: #FEF3C7;
            color: #D97706;
            border-radius: 10px;
            font-size: 6px;
            font-weight: bold;
            margin-top: 2px;
        }

        .status.approved {
            background: #D1FAE5;
            color: #059669;
        }

        .status.completed {
            background: #DBEAFE;
            color: #2563EB;
        }

        .status.rejected {
            background: #FEE2E2;
            color: #DC2626;
        }

        /* Client Info Section */
        .client-section {
            background: #F9FAFB;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 12px;
        }

        .client-info,
        .order-info {
            display: inline-block;
            width: 48%;
            vertical-align: top;
        }

        .client-info h3,
        .order-info h3 {
            font-size: 9px;
            font-weight: bold;
            color: #001c34;
            margin-bottom: 5px;
        }

        .client-info p,
        .order-info p {
            font-size: 7px;
            margin: 2px 0;
            color: #555;
        }

        .client-info strong,
        .order-info strong {
            color: #333;
        }

        /* Table Styles */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 7px;
        }

        .items-table th {
            background: #001c34;
            color: white;
            padding: 5px 4px;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
        }

        .items-table td {
            padding: 4px 4px;
            border-bottom: 0.5px solid #E5E7EB;
            font-size: 7px;
        }

        .items-table tr:last-child td {
            border-bottom: none;
        }

        .items-table .text-right {
            text-align: right;
        }

        .items-table .text-center {
            text-align: center;
        }

        /* Breakdown Table */
        .breakdown-section {
            margin-bottom: 12px;
        }

        .breakdown-title {
            font-size: 9px;
            font-weight: bold;
            color: #001c34;
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid #E5E7EB;
        }

        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 6px;
        }

        .breakdown-table th {
            background: #F3F4F6;
            padding: 4px 3px;
            text-align: left;
            font-size: 6px;
            font-weight: bold;
            color: #555;
        }

        .breakdown-table td {
            padding: 3px 3px;
            border-bottom: 0.5px solid #E5E7EB;
            font-size: 6px;
        }

        /* Addons Table */
        .addons-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 7px;
        }

        .addons-table th {
            background: #F3F4F6;
            padding: 4px 6px;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
        }

        .addons-table td {
            padding: 3px 6px;
            border-bottom: 0.5px solid #E5E7EB;
            font-size: 7px;
        }

        /* Summary Section */
        .summary-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 12px;
        }

        .summary-box {
            width: 100%;
            border: 0.5px solid #E5E7EB;
            border-radius: 4px;
            overflow: hidden;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 10px;
            border-bottom: 0.5px solid #E5E7EB;
            font-size: 7px;
        }

        .summary-row.total {
            background: #001c34;
            color: white;
            font-weight: bold;
            font-size: 8px;
        }

        .summary-row.discount {
            background: #FEF3C7;
            color: #D97706;
        }

        .summary-label {
            font-size: 7px;
        }

        .summary-value {
            font-weight: bold;
        }

        /* Payment Summary */
        .payment-section {
            background: #F9FAFB;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 12px;
        }

        .payment-title {
            font-size: 9px;
            font-weight: bold;
            color: #001c34;
            margin-bottom: 6px;
        }

        .payment-grid {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .payment-item {
            text-align: center;
            padding: 6px;
            background: white;
            border-radius: 4px;
            border: 0.5px solid #E5E7EB;
            margin-bottom: 6px;
        }

        .pay-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pay-col {
            vertical-align: top;
            padding: 0;
        }

        .pay-col-left {
            width: 44%;
            padding-right: 10px;
        }

        .pay-col-right {
            width: 56%;
        }

        .payment-label {
            font-size: 6px;
            color: #666;
            margin-bottom: 3px;
        }

        .payment-amount {
            font-size: 10px;
            font-weight: bold;
            color: #001c34;
        }

        /* Notes Section */
        .notes-section {
            background: #FFFBEB;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 12px;
            border-left: 2px solid #F59E0B;
        }

        .notes-title {
            font-size: 8px;
            font-weight: bold;
            color: #D97706;
            margin-bottom: 4px;
        }

        .notes-content {
            font-size: 7px;
            color: #555;
            line-height: 1.3;
        }

        /* Footer */
        .footer {
            margin-top: 15px;
            padding-top: 10px;
            text-align: center;
            border-top: 0.5px solid #E5E7EB;
            font-size: 6px;
            color: #999;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 10px;
        }

        .signature-box {
            width: 180px;
            text-align: center;
        }

        .signature-line {
            margin-top: 15px;
            padding-top: 5px;
            border-top: 0.5px solid #333;
        }

        .signature-label {
            font-size: 7px;
            color: #666;
            margin-top: 3px;
        }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 8px;
            font-size: 6px;
            font-weight: bold;
        }

        .text-muted {
            color: #999;
            font-size: 6px;
        }

        .page-break {
            page-break-before: always;
        }

        /* Compact spacing */
        .mt-1 {
            margin-top: 3px;
        }

        .mb-1 {
            margin-bottom: 3px;
        }

        .pt-1 {
            padding-top: 3px;
        }

        .pb-1 {
            padding-bottom: 3px;
        }

        /* Hide less important details on very small screens */
        @media (max-width: 400px) {

            .breakdown-table th:nth-child(3),
            .breakdown-table td:nth-child(3),
            .breakdown-table th:nth-child(4),
            .breakdown-table td:nth-child(4),
            .breakdown-table th:nth-child(5),
            .breakdown-table td:nth-child(5) {
                display: none;
            }
        }
        /* Change 5 — design/mockup + proof-of-payment image sections */
        .assets-section {
            margin-bottom: 12px;
        }

        .assets-title {
            font-size: 9px;
            font-weight: bold;
            color: #1F2937;
            border-bottom: 0.5px solid #E5E7EB;
            padding-bottom: 3px;
            margin-bottom: 6px;
        }

        .asset-card {
            display: inline-block;
            vertical-align: top;
            width: 140px;
            margin: 0 8px 8px 0;
            text-align: center;
        }

        .asset-img {
            width: 140px;
            height: auto;
            border: 0.5px solid #E5E7EB;
            border-radius: 4px;
            background: #FAFAFA;
        }

        .asset-label {
            font-size: 7px;
            font-weight: bold;
            color: #374151;
            margin-top: 3px;
        }

        .asset-meta {
            font-size: 6px;
            color: #777;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>Sorbetes Apparel Studio</h1>
                <p>117 Mother Ignacia Street, Quezon City, Philippines</p>
                <p>Phone: +63 927 401 7484 | Email: sales@alphacentauri.ph</p>
            </div>
            <div class="quotation-info">
                <div class="quotation-number">QUOTATION #{{ $quotation->quotation_id }}</div>
                <div>Date: {{ $quotation->created_at->format('F d, Y') }}</div>
            </div>
        </div>

        <!-- Client Information -->
        <div class="client-section">
            <div class="client-info">
                <h3>Bill To:</h3>
                <p><strong>{{ $quotation->client_name ?? 'N/A' }}</strong></p>
                <p>Email: {{ $quotation->client_email ?? 'N/A' }}</p>
                <p>Brand: {{ $quotation->client_brand ?? 'N/A' }}</p>
            </div>
            <div class="order-info">
                <h3>Order Details:</h3>
                <p><strong>Shirt Color:</strong> {{ $quotation->shirt_color ?? 'N/A' }}</p>
                <p><strong>Free Items:</strong> {{ $quotation->free_items ?? 'None' }}</p>
                @php
                    // Issue 7/12 — label specs are cast to arrays on the model.
                    $brandLabel = is_array($quotation->brand_label_json) ? $quotation->brand_label_json : [];
                    $careLabel  = is_array($quotation->care_label_json) ? $quotation->care_label_json : [];
                    $fmtLabel = function (array $l) {
                        if (empty($l['enabled'])) { return null; }
                        $parts = array_filter([
                            $l['material'] ?? null,
                            $l['method'] ?? null,
                            $l['placement'] ?? null,
                            ($l['measurement'] ?? null) ? '(' . $l['measurement'] . ')' : null,
                        ]);
                        return $parts ? implode(' · ', $parts) : 'Enabled';
                    };
                    $brandText = $fmtLabel($brandLabel);
                    $careText  = $fmtLabel($careLabel);
                @endphp
                @if($brandText)
                    <p><strong>Brand Label:</strong> {{ $brandText }}</p>
                @endif
                @if($careText)
                    <p><strong>Care/Size Label:</strong> {{ $careText }}</p>
                @endif
            </div>
        </div>

        {{-- Change 5: Design and Mockups. Omitted when nothing resolves. --}}
        @if (!empty($mockups) || !empty($designAssets))
            <div class="assets-section">
                <div class="assets-title">Design &amp; Mockups</div>
                <div>
                    @foreach ($mockups as $m)
                        <div class="asset-card">
                            <img class="asset-img" src="{{ $m['src'] }}" alt="{{ $m['label'] }}">
                            <div class="asset-label">{{ $m['label'] }}</div>
                            @if (!empty($m['meta']))
                                <div class="asset-meta">{{ $m['meta'] }}</div>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($designAssets as $a)
                        <div class="asset-card">
                            <img class="asset-img" src="{{ $a['src'] }}" alt="{{ $a['label'] }}">
                            <div class="asset-label">{{ $a['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th class="text-center">Size</th>
                    <th class="text-center">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $items = $quotation->items_json;
                    $addons = $quotation->addons_json;
                    $breakdown = $quotation->breakdown_json;
                @endphp

                @forelse($items as $item)
                    <tr>
                        <td>
                            <strong>{{ $item['tshirt_type'] ?? 'T-Shirt' }}</strong>
                            @if (($item['print_type'] ?? '') || ($item['print_pattern'] ?? '') || ($item['neckline'] ?? ''))
                                <br>
                                <span class="text-muted">
                                    {{ $item['print_type'] ?? '' }}
                                    {{ $item['print_type'] && $item['print_pattern'] ? '|' : '' }}
                                    {{ $item['print_pattern'] ?? '' }}
                                    {{ ($item['print_type'] || $item['print_pattern']) && $item['neckline'] ? '|' : '' }}
                                    {{ $item['neckline'] ?? '' }}
                                </span>
                            @endif
                        </td>
                        <td class="text-center">{{ $item['size'] ?? 'N/A' }}</td>
                        <td class="text-center">{{ $item['quantity'] ?? 0 }}</td>
                        <td class="text-right">₱{{ number_format($item['price_per_piece'] ?? 0, 2) }}</td>
                        <td class="text-right">
                            ₱{{ number_format(($item['quantity'] ?? 0) * ($item['price_per_piece'] ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center">No items found</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Per-Color Quantity Breakdown. Each shirt colour carries its own
             independent size/quantity list so production knows how many of
             each colour to cut/produce. Display/allocation only — pricing is
             on the summed Items Table above. Omitted cleanly for colour-less
             (legacy) quotations, where this key is absent/empty. --}}
        @php
            $colorBreakdowns = (is_array($quotation->breakdown_json)
                ? ($quotation->breakdown_json['color_breakdowns'] ?? [])
                : []);
            $colorBreakdowns = is_array($colorBreakdowns) ? $colorBreakdowns : [];
        @endphp
        @if (!empty($colorBreakdowns))
            <div class="breakdown-section">
                <div class="breakdown-title">Per-Color Quantity Breakdown</div>
                @foreach ($colorBreakdowns as $group)
                    <div style="margin-bottom: 6px;">
                        <div style="font-size: 7px; font-weight: bold; color: #001c34; margin-bottom: 2px;">
                            {{ $group['color'] ?? 'Unspecified Color' }}
                            <span style="font-weight: normal; color: #666;">
                                — {{ $group['subtotal_qty'] ?? array_sum(array_map(fn ($s) => (int) ($s['quantity'] ?? 0), is_array($group['sizes'] ?? null) ? $group['sizes'] : [])) }} pcs
                            </span>
                        </div>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>Size</th>
                                    <th class="text-center">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ((is_array($group['sizes'] ?? null) ? $group['sizes'] : []) as $row)
                                    <tr>
                                        <td>{{ $row['size'] ?? 'N/A' }}</td>
                                        <td class="text-center">{{ $row['quantity'] ?? 0 }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-center">No sizes</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Cost Breakdown Section -->
        @if (!empty($breakdown['items']))
            <div class="breakdown-section">
                <div class="breakdown-title">Cost Breakdown</div>
                <div style="overflow-x: auto;">
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>Size</th>
                                <th class="text-center">Qty</th>
                                <th class="text-right">Tshirt</th>
                                <th class="text-right">Size+</th>
                                <th class="text-right">Neckline</th>
                                <th class="text-right">Print</th>
                                <th class="text-right">Color</th>
                                <th class="text-right">Pattern</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($breakdown['items'] as $item)
                                <tr>
                                    <td>{{ $item['size'] ?? 'N/A' }}</td>
                                    <td class="text-center">{{ $item['quantity'] ?? 0 }}</td>
                                    <td class="text-right">₱{{ number_format($item['tshirt_price'] ?? 0, 2) }}</td>
                                    <td class="text-right">₱{{ number_format($item['size_price'] ?? 0, 2) }}</td>
                                    <td class="text-right">₱{{ number_format($item['neckline_price'] ?? 0, 2) }}</td>
                                    <td class="text-right">₱{{ number_format($item['print_type_price'] ?? 0, 2) }}</td>
                                    <td class="text-right">₱{{ number_format($item['print_color_price'] ?? 0, 2) }}
                                    </td>
                                    <td class="text-right">₱{{ number_format($item['print_pattern_price'] ?? 0, 2) }}
                                    </td>
                                    <td class="text-right">₱{{ number_format($item['total'] ?? 0, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- Addons Section -->
        @if (!empty($addons))
            <div>
                <div class="breakdown-title">Add-ons</div>
                <table class="addons-table">
                    <thead>
                        <tr>
                            <th>Add-on Name</th>
                            <th class="text-right">Price/pc</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($addons as $addon)
                            <tr>
                                <td>{{ $addon['name'] ?? 'N/A' }}</td>
                                <td class="text-right">
                                    @if (($addon['price'] ?? 0) > 0)
                                        ₱{{ number_format($addon['price'], 2) }}
                                    @else
                                        Free
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <!-- Summary -->
        <!-- Payment Summary (totals + schedule) -->
        <div class="payment-section">
            <div class="payment-title">Payment Summary</div>
            <table class="pay-table">
                <tr>
                    <td class="pay-col pay-col-left">
                        <div class="summary-box">
                            <div class="summary-row">
                                <span class="summary-label">Subtotal:</span>
                                <span class="summary-value">₱{{ number_format($quotation->subtotal, 2) }}</span>
                            </div>

                            @if ($quotation->discount_price > 0)
                                <div class="summary-row discount">
                                    <span class="summary-label">
                                        Discount ({{ ucfirst($quotation->discount_type) }}):
                                        @if ($quotation->discount_type == 'percentage')
                                            ({{ $quotation->discount_price }}% off)
                                        @endif
                                    </span>
                                    <span class="summary-value">
                                        -₱{{ number_format($quotation->discount_price, 2) }}
                                    </span>
                                </div>
                            @endif

                            <div class="summary-row total">
                                <span class="summary-label">Grand Total:</span>
                                <span class="summary-value">₱{{ number_format($quotation->grand_total, 2) }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="pay-col pay-col-right">
                        <div class="payment-item">
                            <div class="payment-label">Downpayment (60%)</div>
                            <div class="payment-amount">₱{{ number_format($quotation->grand_total * 0.6, 2) }}</div>
                            <div class="text-muted">Due upon order confirmation</div>
                        </div>
                        <div class="payment-item">
                            <div class="payment-label">Balance (40%)</div>
                            <div class="payment-amount">₱{{ number_format($quotation->grand_total * 0.4, 2) }}</div>
                            <div class="text-muted">Due upon delivery</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        {{-- Change 5: Proof of Payment. Verified payments from the converted order. --}}
        @if (!empty($paymentProofs))
            <div class="assets-section">
                <div class="assets-title">Proof of Payment</div>
                <div>
                    @foreach ($paymentProofs as $p)
                        <div class="asset-card">
                            <img class="asset-img" src="{{ $p['src'] }}" alt="{{ $p['label'] }}">
                            <div class="asset-label">{{ $p['label'] }}</div>
                            <div class="asset-meta">
                                @if (!is_null($p['amount']))₱{{ number_format((float) $p['amount'], 2) }}@endif
                                @if (!empty($p['ref'])) · Ref: {{ $p['ref'] }}@endif
                            </div>
                            @if (!empty($p['verifiedAt']))
                                <div class="asset-meta">
                                    Verified {{ \Illuminate\Support\Carbon::parse($p['verifiedAt'])->format('M d, Y') }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Notes -->
        @if ($quotation->notes)
            <div class="notes-section">
                <div class="notes-title">📝 Notes</div>
                <div class="notes-content">
                    {{ $quotation->notes }}
                </div>
            </div>
        @endif

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Client Signature</div>
                <div class="text-muted">Date: _____________</div>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Authorized Signature</div>
                <div class="text-muted">Date: _____________</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>Terms: 60% downpayment to start production. Balance due upon completion.</p>
            <p>Valid for 30 days from date of issue.</p>
        </div>
    </div>
</body>

</html>