<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cancellation Request {{ ucfirst($decision) }}</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: #fff; border-radius: 8px; overflow: hidden; }
        .header { background: {{ $decision === 'accepted' ? '#16a34a' : '#dc2626' }}; color: #fff; padding: 24px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .body { padding: 28px 32px; color: #333; line-height: 1.6; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-weight: bold; font-size: 14px;
                 background: {{ $decision === 'accepted' ? '#dcfce7' : '#fee2e2' }};
                 color: {{ $decision === 'accepted' ? '#15803d' : '#b91c1c' }}; }
        .note-box { background: #f8f8f8; border-left: 4px solid #999; padding: 12px 16px; margin-top: 16px; border-radius: 4px; }
        .footer { padding: 16px 32px; font-size: 12px; color: #888; border-top: 1px solid #eee; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Cancellation Request {{ $decision === 'accepted' ? 'Accepted ✓' : 'Rejected ✗' }}</h1>
    </div>
    <div class="body">
        <p>Hello,</p>
        <p>
            Your cancellation request for order <strong>#{{ $orderNumber }}</strong>
            has been <span class="badge">{{ strtoupper($decision) }}</span>.
        </p>

        @if($decision === 'accepted')
            <p>Your order has been cancelled and a refund has been initiated. Please allow 3–5 business days for the amount to appear in your account.</p>
        @else
            <p>Your order will continue to be processed as normal.</p>
        @endif

        @if($adminNote)
            <div class="note-box">
                <strong>Note from our team:</strong><br>
                {{ $adminNote }}
            </div>
        @endif

        <p style="margin-top: 24px;">If you have any questions, please contact our support team.</p>
        <p>Thank you for shopping with us.</p>
    </div>
    <div class="footer">
        This email was sent to you because you submitted a cancellation request. &copy; {{ date('Y') }} Store.
    </div>
</div>
</body>
</html>
