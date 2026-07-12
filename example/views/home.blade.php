@extends('layouts.app')

@section('title', 'Batframe — Home')

@section('content')
    <h1>Hello, {{ $name }}!</h1>
    <p>This page was rendered by <strong>Batframe</strong> through BladeOne.</p>
    <p>Try the API endpoints:</p>
    <ul>
        <li><code>GET /users</code> — list users (JSON)</li>
        <li><code>GET /user/1</code> — a single user (JSON)</li>
        <li><code>POST /users</code> — create a user</li>
        <li><a href="/about">/about</a> — a static page from <code>pages/</code></li>
    </ul>
@endsection
