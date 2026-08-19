<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\LegalDocuments;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function terms(): Response
    {
        return self::render(LegalDocuments::terms());
    }

    public function privacy(): Response
    {
        return self::render(LegalDocuments::privacy());
    }

    public function aiUse(): Response
    {
        return self::render(LegalDocuments::aiUse());
    }

    public function trainingDisclaimer(): Response
    {
        return self::render(LegalDocuments::trainingDisclaimer());
    }

    /**
     * @param  array{slug: string, title: string, updated: string, intro: string, sections: list<array{heading: string, paragraphs: list<string>}>}  $document
     */
    private static function render(array $document): Response
    {
        return Inertia::render('Legal/Document', $document);
    }
}
