<?php

/**
 * Imports the Legal Tools page texts from the EISocialHub guide
 * docs/12-testi-legali-e-banner.md (section 4) into the `legal_page` table.
 *
 * The texts are read from the EISocialHub repository at run time and are
 * never copied into this repository.
 *
 * Usage (inside the humhub container, see tests/docker/setup.sh):
 *   php import-pages.php /eisocialhub/docs/12-testi-legali-e-banner.md
 *
 * Database connection: the HUMHUB_CONFIG__COMPONENTS__DB__* variables.
 */

if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Guide not found: " . ($argv[1] ?? '(none)') . "\n");
    exit(1);
}
$guide = file_get_contents($argv[1]);
$language = $argv[2] ?? 'it';

/**
 * Returns the text of one "### 4.x" subsection of the guide.
 */
function section(string $guide, string $number): string
{
    $quoted = preg_quote($number, '/');
    // Ends at the next numbered heading ("### 4.2 ...", "## 5. ..."): the
    // headings inside the page texts have no number.
    if (!preg_match('/^### ' . $quoted . ' .*?(?=^#{2,3} \d|\z)/ms', $guide, $m)) {
        throw new RuntimeException("Section $number not found in the guide");
    }
    return $m[0];
}

/**
 * Returns the first ```markdown block of a section.
 */
function markdownBlock(string $section): string
{
    if (!preg_match('/^```markdown\n(.*?)^```/ms', $section, $m)) {
        throw new RuntimeException('No markdown block in: ' . strtok($section, "\n"));
    }
    return rtrim($m[1]) . "\n";
}

/**
 * Returns the backquoted value after a label such as "Titolo:".
 */
function inlineValue(string $section, string $labelPattern): string
{
    if (!preg_match('/' . $labelPattern . '[^`\n]*`([^`]+)`/mu', $section, $m)) {
        throw new RuntimeException("Value '$labelPattern' not found in: " . strtok($section, "\n"));
    }
    return $m[1];
}

// page_key => [section, title]. For the cookie notice the title is the
// button label (Legal Tools stores it in the title column).
$pages = [];
foreach (['imprint' => '4.1', 'terms' => '4.2', 'privacy' => '4.3', 'update' => '4.5'] as $key => $number) {
    $section = section($guide, $number);
    $pages[$key] = [inlineValue($section, 'Titolo:'), markdownBlock($section)];
}
$cookies = section($guide, '4.4');
$pages['cookies'] = [inlineValue($cookies, 'Accetta etichetta bottone'), markdownBlock($cookies)];

$pdo = new PDO(
    getenv('HUMHUB_CONFIG__COMPONENTS__DB__DSN'),
    getenv('HUMHUB_CONFIG__COMPONENTS__DB__USERNAME'),
    getenv('HUMHUB_CONFIG__COMPONENTS__DB__PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'],
);

// Insert or replace (unique key: page_key + language). Replacing a text does
// not ask users to accept it again: use "Reset confirmations" in the admin page.
$stmt = $pdo->prepare(
    'INSERT INTO legal_page (page_key, language, title, content, updated_at)
     VALUES (:key, :language, :title, :content, NOW())
     ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), updated_at = NOW()',
);
foreach ($pages as $key => [$title, $content]) {
    $stmt->execute(['key' => $key, 'language' => $language, 'title' => $title, 'content' => $content]);
    printf("  %-8s %s (%d characters)\n", $key, $title, mb_strlen($content));
}
