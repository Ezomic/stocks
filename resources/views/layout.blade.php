<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Stocks')</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
@auth
<nav>
    <span class="brand">&#9650; Stocks</span>
    <a href="/" @class(['active' => request()->is('/')])>Dashboard</a>
    <a href="/positions" @class(['active' => request()->is('positions*')])>Positions</a>
    <a href="/rules" @class(['active' => request()->is('rules*')])>Rules</a>
    <a href="/orders" @class(['active' => request()->is('orders*')])>Orders</a>
    <a href="/settings" @class(['active' => request()->is('settings*')])>Settings</a>
    <form method="POST" action="/logout" style="margin:0">
        @csrf
        <button type="submit" class="btn btn-sm" style="border:none;background:none;color:var(--muted);cursor:pointer;font-size:13px;">Logout</button>
    </form>
</nav>
@endauth
<div class="container">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif
    @yield('content')
</div>
</body>
</html>
