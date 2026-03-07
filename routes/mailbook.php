<?php

use App\Mail\ArtistInvitationMail;
use App\Models\Appointment;
use App\Models\ArtistInvitation;
use App\Models\Message;
use App\Models\Studio;
use App\Models\Tattoo;
use App\Models\TattooLead;
use App\Models\User;
use App\Notifications\AffiliationAcceptedNotification;
use App\Notifications\ArtistInvitationNotification;
use App\Notifications\ArtistJoinRequestNotification;
use App\Notifications\ArtistTaggedNotification;
use App\Notifications\BookingAcceptedNotification;
use App\Notifications\BookingDeclinedNotification;
use App\Notifications\BookingRequestNotification;
use App\Notifications\BooksOpenNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\StudioInvitationNotification;
use App\Notifications\StudioOwnerInvitationNotification;
use App\Models\StudioInvitation;
use App\Notifications\TattooApprovedNotification;
use App\Notifications\TattooBeaconNotification;
use App\Notifications\TattooRejectedNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\WelcomeNotification;
use Xammie\Mailbook\Mailbook;

// Helper to get or create sample models for previews
$getUser = fn () => User::first() ?? new User(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
$getArtist = fn () => User::where('type_id', 2)->first() ?? new User(['name' => 'Ink Master', 'email' => 'artist@example.com']);
$getStudio = fn () => Studio::first() ?? new Studio(['name' => 'Ink Masters Studio']);
$getTattoo = fn () => Tattoo::first() ?? new Tattoo(['title' => 'Sample Tattoo', 'description' => 'A beautiful piece']);
$getAppointment = fn () => Appointment::first() ?? new Appointment(['date' => '2026-04-01', 'start_time' => '14:00', 'end_time' => '16:00', 'status' => 'pending', 'type' => 'appointment']);
$getMessage = fn () => Message::first() ?? new Message(['content' => 'Hey, I love your work! Are you available next week?']);
$getLead = fn () => TattooLead::first() ?? new TattooLead(['timing' => 'month', 'description' => 'Looking for a floral sleeve']);
$getInvitation = fn () => ArtistInvitation::first() ?? new ArtistInvitation(['artist_name' => 'Jane Doe', 'studio_name' => 'Ink Masters Studio', 'location' => 'Austin, TX', 'email' => 'jane@example.com', 'token' => 'sample-token-123']);
$getStudioInvitation = fn () => StudioInvitation::with('studio')->first() ?? (function () use ($getStudio, $getUser) {
    $inv = new StudioInvitation(['email' => 'owner@example.com', 'token' => 'sample-studio-token-123']);
    $inv->setRelation('studio', $getStudio());
    $inv->setRelation('invitedBy', $getUser());
    return $inv;
})();

// -- Mailable --

Mailbook::add(function () use ($getInvitation, $getUser) {
    return new ArtistInvitationMail($getInvitation(), $getUser());
})->label('Artist Invitation (Mail)');

// -- Auth & Account --

Mailbook::to($getUser())
    ->add(new VerifyEmailNotification())
    ->label('Verify Email');

Mailbook::to($getUser())
    ->add(new ResetPasswordNotification('sample-reset-token'))
    ->label('Reset Password');

Mailbook::to($getUser())
    ->add(new WelcomeNotification())
    ->label('Welcome');

// -- Booking --

Mailbook::to($getArtist())
    ->add(fn () => new BookingRequestNotification($getAppointment()))
    ->label('Booking Request');

Mailbook::to($getUser())
    ->add(fn () => new BookingAcceptedNotification($getAppointment()))
    ->label('Booking Accepted');

Mailbook::to($getUser())
    ->add(fn () => new BookingDeclinedNotification($getAppointment(), 'Schedule conflict'))
    ->label('Booking Declined');

// -- Messages --

Mailbook::to($getUser())
    ->add(fn () => new NewMessageNotification($getMessage(), $getArtist()))
    ->label('New Message');

// -- Tattoo Tags --

Mailbook::to($getArtist())
    ->add(fn () => new ArtistTaggedNotification($getTattoo(), $getUser()))
    ->label('Artist Tagged in Tattoo');

Mailbook::to($getUser())
    ->add(fn () => new TattooApprovedNotification($getTattoo(), $getArtist()))
    ->label('Tattoo Approved');

Mailbook::to($getUser())
    ->add(fn () => new TattooRejectedNotification($getTattoo(), $getArtist()))
    ->label('Tattoo Rejected');

// -- Artist Invitations --

Mailbook::to('artist@example.com')
    ->add(fn () => new ArtistInvitationNotification($getInvitation(), $getUser()))
    ->label('Artist Invitation (Notification)');

// -- Studio --

Mailbook::to($getArtist())
    ->add(fn () => new StudioInvitationNotification($getStudio(), $getUser()))
    ->label('Studio Invitation');

Mailbook::to($getUser())
    ->add(fn () => new ArtistJoinRequestNotification($getArtist(), $getStudio()))
    ->label('Artist Join Request');

Mailbook::to($getUser())
    ->add(fn () => new AffiliationAcceptedNotification($getArtist(), $getStudio(), 'artist'))
    ->label('Affiliation Accepted');

Mailbook::to('owner@example.com')
    ->add(fn () => new StudioOwnerInvitationNotification($getStudioInvitation(), $getUser()))
    ->label('Studio Owner Invitation');

// -- Leads & Availability --

Mailbook::to($getArtist())
    ->add(fn () => new TattooBeaconNotification($getLead(), $getUser()))
    ->label('Tattoo Beacon (Lead)');

Mailbook::to($getUser())
    ->add(fn () => new BooksOpenNotification($getArtist()))
    ->label('Books Open');
