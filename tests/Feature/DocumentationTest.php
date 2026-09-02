<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_requires_authentication(): void
    {
        auth()->logout();

        $this->get(route('documentation'))->assertRedirect(route('login'));
    }

    public function test_application_manual_is_available_inside_the_application(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('documentation'))
            ->assertOk()
            ->assertSee('FTTH Manager — kompletno korisničko uputstvo')
            ->assertSee('Auto ODO i GIS planiranje');
    }

    public function test_geodetic_txt_standard_is_consolidated_into_the_application_manual(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('documentation', ['document' => 'geodetski-txt']))
            ->assertOk()
            ->assertSee('FTTH Manager — kompletno korisničko uputstvo')
            ->assertSee('Obavezni format TXT fajla')
            ->assertSee('BROJ X Y Z OPIS');
    }

    public function test_unknown_page_uses_the_branded_not_found_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/ova-stranica-ne-postoji')
            ->assertNotFound()
            ->assertSee('Stranica nije pronađena')
            ->assertSee('Nazad na pregled');
    }
}
