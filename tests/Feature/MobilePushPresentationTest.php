<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobilePushPresentation;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class MobilePushPresentationTest extends TestCase
{
    public function test_preview_is_short_plain_text_and_preserves_authored_language(): void
    {
        $source = ['actor' => (object)['display_name' => "Sender\nName"],
            'message' => (object)['body_encrypted' => Crypt::encryptString("Olá\n\u{202e}".str_repeat('😀', 250))]];
        $data = app(MobilePushPresentation::class)->notification('en', $source);
        self::assertSame('Sender Name', $data['title']);
        self::assertSame(180, mb_strlen($data['body']));
        self::assertStringStartsWith('Olá ', $data['body']);
        self::assertStringEndsWith('…', $data['body']);
        self::assertStringNotContainsString("\u{202e}", $data['body']);
    }

    public function test_generic_and_attachment_fallbacks_follow_recipient_language(): void
    {
        $presentation = app(MobilePushPresentation::class);
        self::assertSame('New notification', $presentation->notification('en')['title']);
        self::assertSame('Neue Mitteilung', $presentation->notification('de')['title']);
        $source = ['actor' => (object)['display_name' => 'Sender'],
            'message' => (object)['body_encrypted' => Crypt::encryptString('')]];
        self::assertSame('Shared an attachment or content.', $presentation->notification('en', $source)['body']);
        $source['message']->body_encrypted = 'Invalid cipher';
        self::assertSame($presentation->notification('de'), $presentation->notification('de', $source));
    }
}
