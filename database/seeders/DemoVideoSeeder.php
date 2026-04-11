<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClientNote;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic demo data for video recordings.
 * Uses Jane Smith (2) as the artist with demo clients.
 *
 * Run: php artisan db:seed --class=DemoVideoSeeder
 */
class DemoVideoSeeder extends Seeder
{
    private int $artistId = 2;

    // Demo clients
    private array $clients = [
        21 => 'Sophia Jones',
        22 => 'Jackson Jones',
        23 => 'Charlotte Green',
        24 => 'Alexander Sanchez',
        25 => 'Amelia Garcia',
    ];

    public function run(): void
    {
        $this->cleanup();

        $now = Carbon::now();

        $this->seedPastAppointments($now);
        $this->seedUpcomingAppointments($now);
        $this->seedConversations($now);
        $this->seedClientNotes();

        $this->command->info('Demo video seed data created successfully.');
    }

    private function cleanup(): void
    {
        $clientIds = array_keys($this->clients);

        // Remove appointments between this artist and demo clients created today
        Appointment::where('artist_id', $this->artistId)
            ->whereIn('client_id', $clientIds)
            ->whereDate('created_at', Carbon::today())
            ->delete();

        // Remove conversations (and their messages/participants) between this artist and demo clients created today
        $conversationIds = ConversationParticipant::where('user_id', $this->artistId)
            ->whereDate('created_at', Carbon::today())
            ->pluck('conversation_id');

        if ($conversationIds->isNotEmpty()) {
            Message::whereIn('conversation_id', $conversationIds)->delete();
            ConversationParticipant::whereIn('conversation_id', $conversationIds)->delete();
            Conversation::whereIn('id', $conversationIds)->delete();
        }

        // Remove client notes from this artist for demo clients created today
        ClientNote::where('studio_user_id', $this->artistId)
            ->whereIn('client_id', $clientIds)
            ->whereDate('created_at', Carbon::today())
            ->delete();

        $this->command->info('  Cleaned up existing demo data from today');
    }

    private function seedPastAppointments(Carbon $now): void
    {
        $past = [
            // Sophia Jones - completed sleeve session
            [
                'title' => 'Japanese Sleeve — Session 3',
                'description' => 'Continuing upper arm koi fish and wave work. Color fill on the koi body and background waves.',
                'client_id' => 21,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(3)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'tattoo',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
                'price' => 520.00,
                'duration_minutes' => 240,
                'notes' => 'Client sat really well for 4 hours. Koi body fully colored — orange/gold with black outline. Background waves roughed in, will refine next session. She wants to extend down to the elbow next time.',
            ],
            // Sophia Jones - earlier session
            [
                'title' => 'Japanese Sleeve — Session 2',
                'description' => 'Outline work for koi and wave composition on upper arm.',
                'client_id' => 21,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(24)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'tattoo',
                'start_time' => '11:00:00',
                'end_time' => '14:30:00',
                'price' => 455.00,
                'duration_minutes' => 210,
                'notes' => 'Finished full outline of the koi and wave composition. Clean lines, no touch-ups needed. Healing from session 1 looks perfect.',
            ],
            // Jackson Jones - completed back piece session
            [
                'title' => 'Geometric Back Piece — Session 1',
                'description' => 'Sacred geometry mandala centered on upper back. Outline and dotwork foundation.',
                'client_id' => 22,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(7)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'tattoo',
                'start_time' => '09:00:00',
                'end_time' => '14:00:00',
                'price' => 650.00,
                'duration_minutes' => 300,
                'notes' => 'Stencil placement took a while — he wanted it perfectly centered. Got through all the primary linework and started the inner dotwork mandala. Very detailed, will need 2 more sessions minimum. He handled the spine well.',
            ],
            // Charlotte Green - small piece completed
            [
                'title' => 'Botanical Forearm Piece',
                'description' => 'Fine-line native fern and pohutukawa flowers, inner forearm.',
                'client_id' => 23,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(12)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'tattoo',
                'start_time' => '13:00:00',
                'end_time' => '15:30:00',
                'price' => 325.00,
                'duration_minutes' => 150,
                'notes' => 'Completed in one session. She loved the delicate linework. Added a small tui bird at the last minute which tied the whole piece together nicely. Great client — brought reference photos that actually made sense.',
            ],
            // Alexander Sanchez - consultation that happened
            [
                'title' => 'Consultation — Chest Panel',
                'description' => 'Initial consult for Polynesian-inspired chest piece.',
                'client_id' => 24,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(5)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'consultation',
                'start_time' => '16:00:00',
                'end_time' => '16:45:00',
                'price' => null,
                'duration_minutes' => 45,
                'notes' => 'Wants a Samoan-inspired chest panel incorporating family symbols. Showed me his grandfather\'s tatau photos for reference. Will need to research traditional patterns — he wants authenticity, not generic tribal. Quoted 3 sessions at $650 each.',
            ],
            // Amelia Garcia - completed piece
            [
                'title' => 'Realism Portrait — Left Calf',
                'description' => 'Black and grey realistic portrait of client\'s dog.',
                'client_id' => 25,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->subDays(18)->format('Y-m-d'),
                'status' => 'completed',
                'type' => 'tattoo',
                'start_time' => '10:00:00',
                'end_time' => '13:30:00',
                'price' => 455.00,
                'duration_minutes' => 210,
                'notes' => 'Portrait of their golden retriever. Reference photo was great quality which made a huge difference. Smooth shading session — really happy with how the eyes came out. Client got emotional when they saw it finished.',
            ],
        ];

        foreach ($past as $data) {
            Appointment::create($data);
        }

        $this->command->info('  Created ' . count($past) . ' past appointments');
    }

    private function seedUpcomingAppointments(Carbon $now): void
    {
        $upcoming = [
            // Sophia Jones - next sleeve session
            [
                'title' => 'Japanese Sleeve — Session 4',
                'description' => 'Wave refinement and background shading. Extending design toward elbow.',
                'client_id' => 21,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->addDays(4)->format('Y-m-d'),
                'status' => 'booked',
                'type' => 'tattoo',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
                'price' => null,
                'duration_minutes' => null,
            ],
            // Jackson Jones - back piece session 2
            [
                'title' => 'Geometric Back Piece — Session 2',
                'description' => 'Continue dotwork mandala and begin outer geometric frame.',
                'client_id' => 22,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->addDays(6)->format('Y-m-d'),
                'status' => 'booked',
                'type' => 'tattoo',
                'start_time' => '09:00:00',
                'end_time' => '14:00:00',
                'price' => null,
                'duration_minutes' => null,
            ],
            // Alexander Sanchez - first tattoo session
            [
                'title' => 'Polynesian Chest Panel — Session 1',
                'description' => 'Begin outline of Samoan-inspired chest panel. Upper pec section.',
                'client_id' => 24,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->addDays(10)->format('Y-m-d'),
                'status' => 'booked',
                'type' => 'tattoo',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
                'price' => null,
                'duration_minutes' => null,
            ],
            // Amelia Garcia - consultation for new piece
            [
                'title' => 'Consultation — Half Sleeve',
                'description' => 'Discuss ideas for a nature-themed half sleeve on right arm.',
                'client_id' => 25,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->addDays(2)->format('Y-m-d'),
                'status' => 'booked',
                'type' => 'consultation',
                'start_time' => '15:00:00',
                'end_time' => '15:30:00',
                'price' => null,
                'duration_minutes' => null,
            ],
            // Charlotte Green - touch-up
            [
                'title' => 'Botanical Touch-Up',
                'description' => 'Quick touch-up on healed fern piece — a few lines to sharpen.',
                'client_id' => 23,
                'artist_id' => $this->artistId,
                'date' => $now->copy()->addDays(14)->format('Y-m-d'),
                'status' => 'booked',
                'type' => 'appointment',
                'start_time' => '11:00:00',
                'end_time' => '12:00:00',
                'price' => null,
                'duration_minutes' => null,
            ],
        ];

        foreach ($upcoming as $data) {
            Appointment::create($data);
        }

        $this->command->info('  Created ' . count($upcoming) . ' upcoming appointments');
    }

    private function seedConversations(Carbon $now): void
    {
        $conversations = [
            // Sophia Jones — ongoing sleeve project chat
            [
                'type' => 'booking',
                'participants' => [$this->artistId, 21],
                'messages' => [
                    ['sender_id' => 21, 'content' => "Hey! I'm so excited to continue the sleeve. The koi looks amazing healed", 'type' => 'text', 'ago_hours' => 72],
                    ['sender_id' => $this->artistId, 'content' => "That's great to hear! The colors held up well?", 'type' => 'text', 'ago_hours' => 71],
                    ['sender_id' => 21, 'content' => "Yes! The orange is so vibrant. My friends can't stop staring at it haha", 'type' => 'text', 'ago_hours' => 70],
                    ['sender_id' => $this->artistId, 'content' => "Love that. For the next session I'm planning to refine the wave backgrounds and start extending toward the elbow. I've sketched a few options", 'type' => 'text', 'ago_hours' => 69],
                    ['sender_id' => 21, 'content' => "Can you send me a photo of the sketches? I'd love to see before the appointment", 'type' => 'text', 'ago_hours' => 68],
                    ['sender_id' => $this->artistId, 'content' => "Yeah of course, I'll send them through tonight once I clean them up", 'type' => 'text', 'ago_hours' => 67],
                    ['sender_id' => 21, 'content' => "Perfect! Also, do I need to do anything different for aftercare on the colored sections?", 'type' => 'text', 'ago_hours' => 48],
                    ['sender_id' => $this->artistId, 'content' => "Same routine — thin layer of moisturizer, keep it out of direct sun. Color areas can peel a bit more so just don't pick at it", 'type' => 'text', 'ago_hours' => 47],
                    ['sender_id' => 21, 'content' => "Got it. See you Saturday!", 'type' => 'text', 'ago_hours' => 46],
                    ['sender_id' => $this->artistId, 'content' => "See you then! Come with a full stomach, it's going to be a long one", 'type' => 'text', 'ago_hours' => 45],
                ],
            ],
            // Jackson Jones — geometric piece discussion
            [
                'type' => 'booking',
                'participants' => [$this->artistId, 22],
                'messages' => [
                    ['sender_id' => 22, 'content' => "Hey, just wanted to check in — my back is healing well. The dotwork looks insane up close", 'type' => 'text', 'ago_hours' => 36],
                    ['sender_id' => $this->artistId, 'content' => "Awesome! Send me a healed photo when you get a chance so I can plan the next session around how it's settled", 'type' => 'text', 'ago_hours' => 35],
                    ['sender_id' => 22, 'content' => "Will do. Quick question — for the outer frame, are you thinking solid lines or more dotwork?", 'type' => 'text', 'ago_hours' => 34],
                    ['sender_id' => $this->artistId, 'content' => "I was thinking a mix — solid geometric lines for the outer frame with dotwork gradients filling the negative space between the mandala and the frame. Creates a nice contrast", 'type' => 'text', 'ago_hours' => 33],
                    ['sender_id' => 22, 'content' => "That sounds perfect. I trust your vision on this one", 'type' => 'text', 'ago_hours' => 32],
                    ['sender_id' => $this->artistId, 'content' => "Appreciate that. I'll have the frame design drawn up before your next session so we can finalize placement", 'type' => 'text', 'ago_hours' => 31],
                ],
            ],
            // Alexander Sanchez — new client consultation follow-up
            [
                'type' => 'consultation',
                'participants' => [$this->artistId, 24],
                'messages' => [
                    ['sender_id' => 24, 'content' => "Hi, I really appreciated the consultation yesterday. You really understood what I'm going for with the family symbols", 'type' => 'text', 'ago_hours' => 96],
                    ['sender_id' => $this->artistId, 'content' => "Thanks for sharing those photos of your grandfather's tatau — it really helps me understand the cultural significance. I want to make sure we honor that properly", 'type' => 'text', 'ago_hours' => 95],
                    ['sender_id' => 24, 'content' => "That means a lot. My family is excited to see the design. Is there anything else you need from me before you start drawing?", 'type' => 'text', 'ago_hours' => 94],
                    ['sender_id' => $this->artistId, 'content' => "If you could send me close-up photos of the specific patterns from your grandfather's tattoo that you want incorporated, that would be super helpful. Also, do you have any placement preferences — centered on the chest or more to one side?", 'type' => 'text', 'ago_hours' => 93],
                    ['sender_id' => 24, 'content' => "Centered for sure. I'll get those photos from my mum this week and send them through", 'type' => 'text', 'ago_hours' => 92],
                    ['sender_id' => $this->artistId, 'content' => "Perfect. I've blocked out 4 hours for our first session on the 11th. We'll get the core outline done and see how the placement feels", 'type' => 'text', 'ago_hours' => 91],
                    ['sender_id' => 24, 'content' => "Sounds good. Can't wait!", 'type' => 'text', 'ago_hours' => 90],
                ],
            ],
            // Charlotte Green — happy client, touch-up scheduling
            [
                'type' => 'booking',
                'participants' => [$this->artistId, 23],
                'messages' => [
                    ['sender_id' => 23, 'content' => "Hi! The fern piece healed beautifully. I've gotten so many compliments", 'type' => 'text', 'ago_hours' => 120],
                    ['sender_id' => $this->artistId, 'content' => "So glad to hear that! The tui was a great call, it really ties the whole piece together", 'type' => 'text', 'ago_hours' => 119],
                    ['sender_id' => 23, 'content' => "Totally agree. There are just a couple of thin lines on the fern fronds that look like they could use a touch-up. Is that normal?", 'type' => 'text', 'ago_hours' => 100],
                    ['sender_id' => $this->artistId, 'content' => "Totally normal with fine-line work, especially on the inner forearm where the skin is thinner. Happy to touch those up for you — no charge since it's within the healing window", 'type' => 'text', 'ago_hours' => 99],
                    ['sender_id' => 23, 'content' => "Oh that's so kind! When works for you?", 'type' => 'text', 'ago_hours' => 98],
                    ['sender_id' => $this->artistId, 'content' => "I've got a gap on the 15th around 11am. Should only take 30-45 minutes", 'type' => 'text', 'ago_hours' => 97],
                    ['sender_id' => 23, 'content' => "Booked! Thank you so much", 'type' => 'text', 'ago_hours' => 96],
                ],
            ],
            // Amelia Garcia — planning new piece
            [
                'type' => 'consultation',
                'participants' => [$this->artistId, 25],
                'messages' => [
                    ['sender_id' => 25, 'content' => "Hey, the portrait of Max healed perfectly. I literally tear up every time I look at it", 'type' => 'text', 'ago_hours' => 60],
                    ['sender_id' => $this->artistId, 'content' => "That honestly makes my day. Pet portraits are always special — glad we could capture his personality", 'type' => 'text', 'ago_hours' => 59],
                    ['sender_id' => 25, 'content' => "I'm already thinking about my next piece. I want to do a nature half sleeve on my right arm. Mountains, native bush, maybe some water elements?", 'type' => 'text', 'ago_hours' => 58],
                    ['sender_id' => $this->artistId, 'content' => "Love that concept. Black and grey like the portrait, or were you thinking of incorporating color this time?", 'type' => 'text', 'ago_hours' => 57],
                    ['sender_id' => 25, 'content' => "I was actually thinking some subtle color — muted greens and blues? Nothing too bright. Kind of like a watercolor wash over realistic linework", 'type' => 'text', 'ago_hours' => 56],
                    ['sender_id' => $this->artistId, 'content' => "That could look incredible. Realistic base with soft color accents is one of my favorite styles to work in. Want to come in for a consult so we can plan placement and talk through the composition?", 'type' => 'text', 'ago_hours' => 55],
                    ['sender_id' => 25, 'content' => "Yes please! When are you free?", 'type' => 'text', 'ago_hours' => 30],
                    ['sender_id' => $this->artistId, 'content' => "I've got a slot this Thursday at 3pm if that works?", 'type' => 'text', 'ago_hours' => 29],
                    ['sender_id' => 25, 'content' => "Perfect, I'll be there. Should I bring reference photos?", 'type' => 'text', 'ago_hours' => 28],
                    ['sender_id' => $this->artistId, 'content' => "Definitely. Anything that captures the vibe — landscapes, color palettes, other tattoos you like. The more reference the better", 'type' => 'text', 'ago_hours' => 27],
                    ['sender_id' => 25, 'content' => "On it. See you Thursday!", 'type' => 'text', 'ago_hours' => 26],
                ],
            ],
        ];

        $count = 0;
        foreach ($conversations as $convData) {
            $conversation = Conversation::create([
                'type' => $convData['type'],
            ]);

            foreach ($convData['participants'] as $userId) {
                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                ]);
            }

            $participants = $convData['participants'];
            foreach ($convData['messages'] as $msg) {
                $recipientId = $msg['sender_id'] === $participants[0]
                    ? $participants[1]
                    : $participants[0];

                $timestamp = Carbon::now()->subHours($msg['ago_hours']);

                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $msg['sender_id'],
                    'recipient_id' => $recipientId,
                    'content' => $msg['content'],
                    'type' => $msg['type'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $count++;
            }
        }

        $this->command->info("  Created " . count($conversations) . " conversations with {$count} messages");
    }

    private function seedClientNotes(): void
    {
        $notes = [
            [
                'client_id' => 21,
                'studio_user_id' => $this->artistId,
                'body' => 'Building a full Japanese sleeve — koi, waves, wind bars. 3 sessions done, needs 2-3 more. Very committed client, always on time. Prefers Saturday mornings.',
            ],
            [
                'client_id' => 22,
                'studio_user_id' => $this->artistId,
                'body' => 'Sacred geometry back piece — large scale project. Handles pain well, even on the spine. Very patient with the process. Works in tech, flexible schedule.',
            ],
            [
                'client_id' => 23,
                'studio_user_id' => $this->artistId,
                'body' => 'Loves native NZ flora. Completed botanical forearm piece in one session. Skin takes ink beautifully — fine lines held up well. Coming back for a touch-up then likely wants a matching piece on the other arm.',
            ],
            [
                'client_id' => 24,
                'studio_user_id' => $this->artistId,
                'body' => 'New client — Samoan heritage, wants authentic Polynesian chest panel incorporating family patterns. Very thoughtful about cultural significance. Need to research traditional motifs. Quoted 3 sessions at $650.',
            ],
            [
                'client_id' => 25,
                'studio_user_id' => $this->artistId,
                'body' => 'Did a realism portrait of his dog (golden retriever) on left calf — turned out amazing. Now wants a nature half sleeve on right arm with subtle color. Great reference photos, easy to work with.',
            ],
        ];

        foreach ($notes as $note) {
            ClientNote::create($note);
        }

        $this->command->info('  Created ' . count($notes) . ' client notes');
    }
}
