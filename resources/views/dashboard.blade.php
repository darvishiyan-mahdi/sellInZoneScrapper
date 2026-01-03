@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div style="background: white; border-radius: 8px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <h1 style="font-size: 1.875rem; font-weight: 700; margin-bottom: 1.5rem; color: #111827;">Multi Scraper Dashboard</h1>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-top: 2rem;">
        <div style="padding: 1.5rem; background: #f9fafb; border-radius: 6px; border-left: 4px solid #3b82f6;">
            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Total Websites</div>
            <div style="font-size: 2rem; font-weight: 700; color: #111827;">0</div>
        </div>
        <div style="padding: 1.5rem; background: #f9fafb; border-radius: 6px; border-left: 4px solid #10b981;">
            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Total Products</div>
            <div style="font-size: 2rem; font-weight: 700; color: #111827;">0</div>
        </div>
    </div>
</div>
@endsection

