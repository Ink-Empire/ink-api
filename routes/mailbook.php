<?php

use App\Mail\ArtistInvitationMail;
use App\Models\ArtistInvitation;
use App\Models\User;
use Xammie\Mailbook\Mailbook;

Mailbook::add(function () {
    $invitation = ArtistInvitation::first() ?? new ArtistInvitation([
        'artist_name' => 'Jane Doe',
        'studio_name' => 'Ink Masters Studio',
        'location' => 'Austin, TX',
        'email' => 'jane@example.com',
        'token' => 'sample-token-123',
    ]);
    $user = User::first() ?? new User(['name' => 'Test User']);

    return new ArtistInvitationMail($invitation, $user);
})->label('Artist Invitation');
