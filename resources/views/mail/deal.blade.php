<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>body{font-family:sans-serif;line-height:1.6;color:#333;} .card{padding:20px;border:1px solid #ddd;border-radius:8px;} th{text-align:left;padding:8px;}</style>
</head>
<body>
    <h2>Deal Confirmed</h2>
    <p>Your quotation has been accepted by the customer.</p>
    <div class="card">
        <table>
            <tr><th>Order / Ref:</th><td>{{ $refnum ?? 'N/A' }}</td></tr>
            <tr><th>Supplier:</th><td>{{ $supplier ?? 'N/A' }}</td></tr>
            <tr><th>Customer:</th><td>{{ $customer ?? 'N/A' }}</td></tr>
            <tr><th>Price:</th><td>{{ isset($price) ? number_format((float) $price, 2) : 'N/A' }}</td></tr>
        </table>
    </div>
    <p>Thank you for using {{ config('app.name') }}.</p>
</body>
</html>
