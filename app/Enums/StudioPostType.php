<?php

namespace App\Enums;

/**
 * What a studio has published on its page.
 *
 * Announcements and guides share one table because they share a publishing
 * envelope: a title, a body, a status and a slug. They differ only in whether
 * they carry dates or a default flag.
 */
enum StudioPostType: string
{
    // Announcements
    case General = 'general';
    case FlashDrop = 'flash_drop';
    case GuestSpot = 'guest_spot';
    case BooksOpen = 'books_open';
    case Travel = 'travel';
    case WalkIns = 'walk_ins';

    // Guides
    case Aftercare = 'aftercare';
    case Prep = 'prep';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Announcement',
            self::FlashDrop => 'Flash drop',
            self::GuestSpot => 'Guest spot',
            self::BooksOpen => 'Books open',
            self::Travel => 'Travel dates',
            self::WalkIns => 'Walk-ins',
            self::Aftercare => 'Aftercare guide',
            self::Prep => 'Preparation guide',
        };
    }

    /**
     * Timely studio news, shown as a banner on the studio page.
     */
    public function isAnnouncement(): bool
    {
        return in_array($this, [
            self::General, self::FlashDrop, self::GuestSpot,
            self::BooksOpen, self::Travel, self::WalkIns,
        ], true);
    }

    /**
     * Evergreen writing, such as aftercare instructions.
     */
    public function isGuide(): bool
    {
        return ! $this->isAnnouncement();
    }

    /**
     * Whether the post gets a URL of its own.
     *
     * Ephemeral notices do not: a permanently indexed "walk-ins available
     * today" page is a liability rather than an asset.
     */
    public function hasPublicPage(): bool
    {
        return ! in_array($this, [self::General, self::WalkIns], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function announcementValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isAnnouncement())
        ));
    }
}
