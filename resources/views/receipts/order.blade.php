<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 10px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #000;
            margin: 0;
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 2px 0;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .item-name {
            width: 55%;
        }

        .item-qty {
            width: 15%;
            text-align: center;
        }

        .item-price {
            width: 30%;
            text-align: right;
        }

        .totals-label {
            text-align: left;
        }

        .totals-value {
            text-align: right;
        }

        .footer {
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="center bold" style="font-size: 13px;">
        {{ $order->outlet->name }}
    </div>
    <div class="center">{{ $order->order_number }}</div>
    <div class="center">{{ $order->created_at->format('d M Y H:i') }}</div>

    <div class="divider"></div>

    <table>
        <tr>
            <td class="totals-label">Table</td>
            <td class="totals-value">{{ $order->table->table_number ?? '-' }}</td>
        </tr>
        @if ($order->customer_name)
            <tr>
                <td class="totals-label">Customer</td>
                <td class="totals-value">{{ $order->customer_name }}</td>
            </tr>
        @endif
    </table>

    <div class="divider"></div>

    <table>
        @foreach ($order->items as $item)
            <tr>
                <td class="item-name">
                    {{ $item->menu->name ?? '-' }}
                    @if ($item->refund_status === 'refunded')
                        <br><span style="font-size: 8px;">(REFUNDED)</span>
                    @endif
                    @if ($item->split_label)
                        <br><span style="font-size: 8px;">Label: {{ $item->split_label }}</span>
                    @endif
                </td>
                <td class="item-qty">x{{ $item->quantity }}</td>
                <td class="item-price">
                    Rp {{ number_format($item->unit_price * $item->quantity, 0, ',', '.') }}
                </td>
            </tr>
        @endforeach
    </table>

    <div class="divider"></div>

    @php
        $subtotal =
            $order->subtotal ??
            $order->items->where('refund_status', 'none')->sum(fn($item) => $item->unit_price * $item->quantity);
        $taxAmount = $order->tax_amount ?? 0;
        $serviceChargeAmount = $order->service_charge_amount ?? 0;
        $grandTotal = $subtotal + $taxAmount + $serviceChargeAmount;
    @endphp

    <table>
        <tr>
            <td class="totals-label">Subtotal</td>
            <td class="totals-value">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
        </tr>
        @if ($order->subtotal !== null)
            <tr>
                <td class="totals-label">VAT (11%)</td>
                <td class="totals-value">Rp {{ number_format($taxAmount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="totals-label">Service Charge</td>
                <td class="totals-value">Rp {{ number_format($serviceChargeAmount, 0, ',', '.') }}</td>
            </tr>
        @endif
        <tr class="bold">
            <td class="totals-label">TOTAL</td>
            <td class="totals-value">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
        </tr>
    </table>

    @if ($order->payments->isNotEmpty())
        <div class="divider"></div>
        <table>
            @foreach ($order->payments as $payment)
                <tr>
                    <td class="totals-label">{{ strtoupper(str_replace('_', ' ', $payment->method)) }}</td>
                    <td class="totals-value">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                </tr>
                @if ($payment->cash_received !== null)
                    <tr>
                        <td class="totals-label">Cash Received</td>
                        <td class="totals-value">Rp {{ number_format($payment->cash_received, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td class="totals-label">Change</td>
                        <td class="totals-value">Rp {{ number_format($payment->change_amount, 0, ',', '.') }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif

    <div class="divider"></div>

    <div class="center footer">Thank you!</div>
</body>

</html>
