<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FieldAccessibilityTest extends TestCase
{
    public function test_invalid_field_announces_its_label_help_and_error_without_duplicate_attributes(): void
    {
        [$document, $xpath] = $this->render(
            '<p id="reading-help">Expresa el valor en centímetros.</p>'
                .'<x-field name="reading" label="Circunferencia" value="12.30" '
                .'aria-describedby="reading-help" aria-invalid="false" />',
            errors: ['reading' => 'Revisa la medición.'],
        );
        $input = $this->input($xpath);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertSame('12.30', $input->getAttribute('value'));
        $this->assertLabel($xpath, $input, 'Circunferencia');
        $descriptions = $this->descriptions($xpath, $input);
        $this->assertSame(['Expresa el valor en centímetros.', 'Revisa la medición.'], $descriptions);
        $this->assertSame(1, $xpath->query('//*[@id="reading-help"]')->length);
        $this->assertSame(1, $document->getElementsByTagName('input')->length);
    }

    public function test_valid_field_keeps_zero_and_help_without_announcing_an_error(): void
    {
        [, $xpath] = $this->render(
            '<p id="reading-help">La escala admite cero.</p>'
                .'<x-field name="reading" label="Valor medido" :value="$value" '
                .'aria-describedby="reading-help" />',
            ['value' => 0],
        );
        $input = $this->input($xpath);
        $this->assertSame('0', $input->getAttribute('value'));
        $this->assertFalse($input->hasAttribute('aria-invalid'));
        $this->assertLabel($xpath, $input, 'Valor medido');
        $this->assertSame(['La escala admite cero.'], $this->descriptions($xpath, $input));
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " field-error ")]')->length);
    }

    public function test_untrusted_label_value_and_error_remain_text_in_the_accessible_form(): void
    {
        $label = 'Medición <img src=x onerror=alert(1)>';
        $value = '\" autofocus onfocus=alert(1) <script>valor</script>';
        $error = 'Revisa <script>alert("error")</script> & confirma la unidad.';
        [, $xpath] = $this->render(
            '<x-field name="reading" :label="$label" :value="$value" />',
            ['label' => $label, 'value' => $value],
            ['reading' => $error],
        );
        $input = $this->input($xpath);
        $this->assertSame($value, $input->getAttribute('value'));
        $this->assertFalse($input->hasAttribute('onfocus'));
        $this->assertFalse($input->hasAttribute('autofocus'));
        $this->assertLabel($xpath, $input, $label);
        $this->assertSame([$error], $this->descriptions($xpath, $input));
        $this->assertSame(0, $xpath->query('//script | //img | //*[@onerror]')->length);
    }

    /** Renderiza Blade real y detecta atributos repetidos que el parser HTML descartaría. */
    private function render(string $template, array $data = [], array $errors = []): array
    {
        $bag = new ViewErrorBag();
        $bag->put('default', new MessageBag($errors));
        View::share('errors', $bag);
        $html = Blade::render($template, $data);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML(
                '<!doctype html><html lang="es"><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>',
                LIBXML_NONET,
            ));
            foreach (libxml_get_errors() as $error) {
                $this->assertNotSame(42, $error->code, 'El HTML no debe repetir atributos: '.$error->message);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [$document, new DOMXPath($document)];
    }

    private function input(DOMXPath $xpath): DOMElement
    {
        $inputs = $xpath->query('//input[@name="reading"]');
        $this->assertSame(1, $inputs->length);

        return $inputs->item(0);
    }

    private function assertLabel(DOMXPath $xpath, DOMElement $input, string $expected): void
    {
        $id = $input->getAttribute('id');
        $this->assertNotSame('', $id);
        $labels = $xpath->query('//label[@for="'.$id.'"]');
        $this->assertSame(1, $labels->length);
        $this->assertSame($expected, trim($labels->item(0)->textContent));
    }

    /** Cada referencia ARIA debe resolver un único elemento legible del formulario. */
    private function descriptions(DOMXPath $xpath, DOMElement $input): array
    {
        $ids = preg_split('/\s+/', trim($input->getAttribute('aria-describedby')));
        $this->assertNotContains('', $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $descriptions = [];
        foreach ($ids as $id) {
            $targets = $xpath->query('//*[@id="'.$id.'"]');
            $this->assertSame(1, $targets->length, 'Una referencia ARIA debe tener un destino único.');
            $descriptions[] = trim($targets->item(0)->textContent);
        }

        return $descriptions;
    }
}
