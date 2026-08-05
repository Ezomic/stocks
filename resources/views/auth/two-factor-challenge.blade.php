@extends('layout')
@section('title', 'Two-factor authentication')
@section('content')

<div class="card" style="max-width:420px;margin:60px auto">
    <h1 style="margin-top:0">Two-factor authentication</h1>
    <p style="font-size:13px;color:var(--muted)">Enter the six digit code from your authenticator app.</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="/two-factor-challenge">
        @csrf
        <label for="code" style="font-size:12px;color:var(--muted)">Authentication code</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
               autofocus style="width:100%;margin-bottom:16px">

        <label for="recovery_code" style="font-size:12px;color:var(--muted)">Or a recovery code</label>
        <input id="recovery_code" type="text" name="recovery_code" autocomplete="off"
               style="width:100%;margin-bottom:16px">

        <button type="submit" class="btn btn-primary" style="width:100%">Continue</button>
    </form>

    <p style="font-size:12px;color:var(--muted);margin-bottom:0">
        <a href="/login">Back to sign in</a>
    </p>
</div>

@endsection
