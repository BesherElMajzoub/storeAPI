<!DOCTYPE html>
<html>

<head>
    <title>Reset Password</title>
</head>

<body style="font-family: sans-serif; line-height: 1.6; color: #333;">
    <h2>Hello!</h2>
    <p>You are receiving this email because we received a password reset request for your account.</p>
    <p>
        <a href="{{ $url }}"
            style="display: inline-block; background: #4F66CD; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
            Reset Password
        </a>
    </p>
    <p>If you did not request a password reset, no further action is required.</p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
    <p style="font-size: 0.9em; color: #666;">
        Link not working? Copy and paste this URL into your browser:<br>
        <a href="{{ $url }}" style="color: #4F66CD;">{{ $url }}</a>
    </p>
</body>

</html>
