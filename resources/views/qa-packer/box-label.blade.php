<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Box Label — {{ $box->qr_code }}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 12px;
            font-size: 9pt;
            color: #000;
        }
        .label {
            border: 2px solid #000;
            border-radius: 4px;
            padding: 10px;
            text-align: center;
        }
        .brand {
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .qr {
            margin: 6px auto;
        }
        .qr img {
            width: 180px;
            height: 180px;
        }
        .code {
            font-family: 'Courier New', monospace;
            font-size: 9pt;
            font-weight: bold;
            margin: 4px 0;
            word-break: break-all;
        }
        .row {
            font-size: 8pt;
            margin: 2px 0;
            text-align: left;
            padding: 0 6px;
        }
        .row .label-key {
            color: #555;
            display: inline-block;
            min-width: 56px;
        }
        .row .label-val {
            font-weight: bold;
        }
        hr {
            border: 0;
            border-top: 1px dashed #999;
            margin: 6px 0;
        }
        .footer {
            font-size: 7pt;
            color: #666;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <div class="label">
        <div class="brand">SORBETES APPAREL STUDIO</div>

        <div class="qr">
            <img src="{{ $qr_data_uri }}" alt="QR Code">
        </div>

        <div class="code">{{ $box->qr_code }}</div>

        <hr>

        <div class="row">
            <span class="label-key">PO:</span>
            <span class="label-val">{{ $order->po_code ?? '—' }}</span>
        </div>
        <div class="row">
            <span class="label-key">Client:</span>
            <span class="label-val">{{ $order->client_brand ?? $order->client_name ?? '—' }}</span>
        </div>
        <div class="row">
            <span class="label-key">Box:</span>
            <span class="label-val">{{ $box->box_number }}</span>
        </div>
        <div class="row">
            <span class="label-key">Pieces:</span>
            <span class="label-val">{{ $total_pieces }} pcs</span>
        </div>
        @if($box->weight_kg)
        <div class="row">
            <span class="label-key">Weight:</span>
            <span class="label-val">{{ number_format($box->weight_kg, 2) }} kg</span>
        </div>
        @endif

        @if(!empty($box->contents_json))
        <hr>
        <div class="row" style="text-align: center; font-size: 7pt; color: #555;">
            CONTENTS
        </div>
        @foreach($box->contents_json as $item)
            <div class="row">
                <span class="label-val">{{ $item['size'] ?? '—' }}:</span>
                {{ $item['qty'] ?? 0 }} pcs
            </div>
        @endforeach
        @endif

        <div class="footer">
            Printed {{ now()->format('Y-m-d H:i') }}
        </div>
    </div>
</body>
</html>
