@extends('layout')
@section('title', 'Login')
@section('content')
<div style="max-width:360px;margin:80px auto;">
    <h1 style="text-align:center;margin-bottom:28px;">&#9650; Stocks</h1>
    <div class="card">
        <form method="POST" action="/login">
            @csrf
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="{{ old('email') }}" autofocus>
                @error('email') <div class="error-text">{{ $message }}</div> @enderror
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Sign in</button>
        </form>
    </div>
</div>
@endsection
