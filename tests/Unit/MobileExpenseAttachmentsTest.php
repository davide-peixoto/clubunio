<?php

namespace Tests\Unit;

use App\ClubUnio\Mobile\MobileExpenseData;
use App\ClubUnio\Models\{ExpenseLine, Tenant};
use Tests\TestCase;

class MobileExpenseAttachmentsTest extends TestCase
{
    public function test_each_existing_attachment_is_indexed_without_exposing_blob_references(): void
    {
        $line = new ExpenseLine;
        $line->forceFill(['id' => 11, 'expense_id' => 7, 'revision' => 2,
            'storage_reference' => 'clubunio/expenses/1/11/private-first.pdf,private-second.png,private-third',
            'filename' => 'Original.pdf', 'content_type' => 'application/pdf', 'file_size' => 123]);
        $line->setRelation('expenseType', null);
        $line->setRelation('gameType', null);
        $data = app(MobileExpenseData::class)->line(new Tenant, $line);
        $this->assertSame(3, $data['AttachmentCount']);
        $this->assertSame([0, 1, 2], array_column($data['Attachments'], 'index'));
        $this->assertSame('Original.pdf', $data['Attachments'][0]['name']);
        $this->assertSame('Beleg 2.png', $data['Attachments'][1]['name']);
        $this->assertSame('image/png', $data['Attachments'][1]['type']);
        $this->assertNull($data['Attachments'][1]['length']);
        $this->assertNull($data['Attachments'][2]['type']);
        $this->assertStringNotContainsString('private-', json_encode($data));
        $this->assertStringNotContainsString('clubunio/', json_encode($data));
    }
}
