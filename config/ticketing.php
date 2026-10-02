<?php
return [
    'api_key'=>env('TICKETING_API_KEY',''),
    'agency_code'=>env('TICKETING_AGENCY','LOCAL'),
    'issuer_code'=>env('TICKETING_ISSUER','999'),
    'currency'=>env('TICKETING_CURRENCY','CNY'),
];
