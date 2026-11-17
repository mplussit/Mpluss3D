<?php
declare(strict_types=1);

/**
 * Felles grensesnitt for alle modellgeneratorer i M+ 3D.
 *
 * En modell vet hvilke parametere den har, hvordan de valideres, hvilke
 * STL-deler som skal lages og hvordan PDF-en ser ut. Alt annet er felles:
 * OpenSCAD-kjøring (ScadGenerator), kontroll av STL (StlInspector),
 * midlertidige filer (GeneratedFiles), bygging og hurtigbuffer (ModelBuilder)
 * og HTTP-API-et (Api).
 *
 * Se README.md, «Legge til en ny modellgenerator».
 */
interface ParametricModel
{
    /** Kort id som brukes i API-et (?model=…), f.eks. "dice". */
    public function key(): string;

    /** Innholdet i konfigurasjonsfila under app/config/models/. */
    public function definition(): array;

    /** Det grensesnittet trenger for å bygge kontrollene (action=schema). */
    public function schema(): array;

    /**
     * Validerer og normaliserer input. Ukjente nøkler ignoreres.
     * @throws ValidationException
     */
    public function validate(array $input): array;

    /**
     * STL-delene modellen består av. Hver del er en egen OpenSCAD-kjøring:
     *
     *   key        kort id for delen, f.eks. "1-4"
     *   scad       relativ sti under models/
     *   variables  tall som sendes med -D
     *   bounds     forventede ytre mål ['min' => [x,y,z], 'max' => [x,y,z]]
     *   shells     (valgfri) antall lukkede skall, 2 for en hul figur (standard 1)
     *   euler      (valgfri) forventet Euler-karakteristikk, 2 − 2 · antall gjennomgående hull
     *   filename   filnavn ved nedlasting
     *   count      hvor mange eksemplarer som skal skrives ut
     *   cache_key  streng som identifiserer geometrien
     *   info       ekstra data som sendes til grensesnittet
     *
     * @return list<array<string,mixed>>
     */
    public function parts(array $params): array;

    /** Ikke-blokkerende advarsler. @return list<string> */
    public function warnings(array $params): array;

    /** Modellbeskrivelse (JSON) for senere integrasjon med M+. */
    public function describe(array $params): array;

    /** Lager PDF-en og returnerer innholdet. */
    public function renderPdf(array $params): string;

    /** Filnavn for PDF-en. */
    public function pdfFilename(array $params): string;

    /** Filnavn for ZIP-fila med alle delene (uten endelse). */
    public function archiveName(array $params): string;

    /** Tekstfil som legges i ZIP-fila (oversikt over antall). */
    public function archiveReadme(array $params, array $parts): string;

    /** Parametere som kan sendes i spørrestrengen (GET). @return list<string> */
    public function queryKeys(): array;
}
