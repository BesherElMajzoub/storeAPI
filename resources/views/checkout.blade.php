<!doctype html>
<html lang="ar">

<head>
    <meta charset="utf-8">
    <title>Stripe Test</title>
</head>

<body>

    <h2>تجربة الدفع عبر Stripe</h2>

    <form method="POST" action="{{ route('stripe.checkout') }}">
        @csrf
        <button type="submit">ادفع الآن</button>
    </form>

</body>

</html>
