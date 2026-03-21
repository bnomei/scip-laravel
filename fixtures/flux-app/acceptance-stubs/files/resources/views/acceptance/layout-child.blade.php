@extends('layouts.acceptance-shell')

@section('content')
    <p>Child content</p>
@endsection

@push('scripts')
    <script>window.acceptanceLayout = true;</script>
@endpush
