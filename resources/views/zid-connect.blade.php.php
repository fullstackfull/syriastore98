<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ربط متجرك على زد</title>
    <style>
        body { margin: 0; padding: 16px; background: #f4f6f8; font-family: Tahoma, Arial, sans-serif; color: #222; }
        .box { max-width: 420px; margin: 40px auto; background: #fff; border-radius: 16px; padding: 28px 24px; box-shadow: 0 4px 20px rgba(0,0,0,.08); text-align: center; }
        .icons { font-size: 40px; margin-bottom: 8px; }
        h1 { font-size: 20px; margin: 8px 0; }
        .hello { font-size: 16px; margin: 16px 0 6px; }
        .benefits { text-align: right; background: #f0faf4; border-radius: 10px; padding: 12px 16px; margin: 16px 0; font-size: 14px; line-height: 1.9; }
        button { width: 100%; padding: 14px; border: 0; border-radius: 10px; background: #1f8a4c; color: #fff; font-size: 16px; font-weight: bold; cursor: pointer; }
        button:hover { background: #18703d; }
        .note { font-size: 12px; color: #777; margin-top: 16px; line-height: 1.7; }
    </style>
</head>
<body>
<div class="box">
    <div class="icons">🏪 🔗 🚚</div>
    <h1>ربط متجرك على زد مع Delivery Syria</h1>
 
    <div class="hello">
        أهلاً <b>{{ $user->business_name ?? $user->name }}</b>
    </div>
    <div>هل تريد ربط متجرك على زد بهذا الحساب؟</div>
 
    <div class="benefits">
        ✓ كل طلب جديد في متجرك يصير طرد تلقائياً<br>
        ✓ بدون نسخ أو إدخال يدوي<br>
        ✓ أجرة التوصيل من محفظتك في Delivery Syria
    </div>
 
    <form method="POST" action="/connect/zid">
        @csrf
        <button type="submit">اربط متجري</button>
    </form>
 
    <div class="note">
        مو هذا حسابك؟ سجّل خروج من Delivery Syria، ثم ارجع وثبّت التطبيق من زد من جديد.
    </div>
</div>
</body>
</html>