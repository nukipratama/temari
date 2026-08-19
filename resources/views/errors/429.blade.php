@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many tries, too fast')
@section('message', 'That is more connection attempts than this address is allowed in a minute, so Strava was not asked again. Wait a minute and start the connect over from the beginning.')
@section('cta', 'Back to sign in')
