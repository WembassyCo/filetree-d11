<?php

declare(strict_types=1);

namespace Drupal\filetree;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service to handle file tree generation.
 */
class FiletreeService {

  /**
   * The file URL generator.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new FiletreeService.
   */
  public function __construct(FileUrlGeneratorInterface $file_url_generator, DateFormatterInterface $date_formatter) {
    $this->fileUrlGenerator = $file_url_generator;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file_url_generator'),
      $container->get('date.formatter')
    );
  }

  /**
   * List files in a directory recursively.
   *
   * @param string $uri
   *   The URI of the directory to scan.
   * @param array $params
   *   Configuration parameters.
   *
   * @return array
   *   Array of file information.
   */
  public function listFiles(string $uri, array $params): array {
    $files = [];
    
    if (!is_dir($uri) || !is_readable($uri)) {
      return $files;
    }

    $iterator = new \DirectoryIterator($uri);
    
    foreach ($iterator as $fileinfo) {
      // Skip hidden files and excluded items
      if ($fileinfo->isDot()) {
        continue;
      }
      
      $basename = $fileinfo->getBasename();
      
      // Check exclude patterns
      foreach ($params['exclude'] as $exclude) {
        if ($this->matchPattern($basename, $exclude)) {
          continue 2;
        }
      }

      $filepath = $fileinfo->getPathname();
      
      if ($fileinfo->isDir()) {
        $files[$basename] = [
          'type' => 'directory',
          'name' => $basename,
          'path' => $filepath,
          'children' => $this->listFiles($filepath, $params),
        ];
      } else {
        $files[$basename] = [
          'type' => 'file',
          'name' => $basename,
          'path' => $filepath,
          'filename' => $fileinfo->getFilename(),
          'extension' => $fileinfo->getExtension(),
          'size' => $fileinfo->getSize(),
          'created' => $fileinfo->getCTime(),
          'modified' => $fileinfo->getMTime(),
        ];
      }
    }

    // Sort files: directories first, then alphabetically
    uksort($files, function ($a, $b) use ($files) {
      $a_is_dir = $files[$a]['type'] === 'directory';
      $b_is_dir = $files[$b]['type'] === 'directory';
      
      if ($a_is_dir !== $b_is_dir) {
        return $a_is_dir ? -1 : 1;
      }
      
      return strnatcasecmp($a, $b);
    });

    return $files;
  }

  /**
   * Check if a name matches a pattern.
   *
   * @param string $name
   *   The name to check.
   * @param string $pattern
   *   The pattern to match against.
   *
   * @return bool
   *   TRUE if the pattern matches.
   */
  protected function matchPattern(string $name, string $pattern): bool {
    // Convert simple wildcards to regex
    $pattern = preg_quote($pattern, '/');
    $pattern = str_replace('\\*', '.*', $pattern);
    $pattern = '/^' . $pattern . '$/i';
    
    return (bool) preg_match($pattern, $name);
  }

  /**
   * Format file size in human-readable format.
   *
   * @param int $size
   *   Size in bytes.
   *
   * @return string
   *   Formatted size string.
   */
  public function formatSize(int $size): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit_index = 0;
    
    while ($size >= 1024 && $unit_index < count($units) - 1) {
      $size /= 1024;
      $unit_index++;
    }
    
    return round($size, 2) . ' ' . $units[$unit_index];
  }

  /**
   * Format a date timestamp.
   *
   * @param int $timestamp
   *   Unix timestamp.
   *
   * @return string
   *   Formatted date string.
   */
  public function formatDate(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'short');
  }

  /**
   * Process token replacements in a string.
   *
   * @param string $text
   *   The text containing tokens.
   * @param array $fileinfo
   *   File information array.
   * @param array $params
   *   Configuration parameters.
   *
   * @return string
   *   Text with tokens replaced.
   */
  public function processTokens(string $text, array $fileinfo, array $params): string {
    $replacements = [
      '[filetree:filename]' => $fileinfo['filename'] ?? $fileinfo['name'],
      '[filetree:basename]' => pathinfo($fileinfo['name'], PATHINFO_FILENAME),
      '[filetree:extension]' => $fileinfo['extension'] ?? pathinfo($fileinfo['name'], PATHINFO_EXTENSION),
      '[filetree:size]' => isset($fileinfo['size']) ? $this->formatSize($fileinfo['size']) : '',
      '[filetree:created]' => isset($fileinfo['created']) ? $this->formatDate($fileinfo['created']) : '',
      '[filetree:modified]' => isset($fileinfo['modified']) ? $this->formatDate($fileinfo['modified']) : '',
    ];

    // Generate link token
    if (isset($fileinfo['path']) && $fileinfo['type'] === 'file') {
      $name = $this->processTokens($params['filename'], $fileinfo, $params);
      $url = $params['absolute']
        ? $this->fileUrlGenerator->generateAbsoluteString($fileinfo['path'])
        : $this->fileUrlGenerator->generateString($fileinfo['path']);
      
      $replacements['[filetree:link]'] = '<a href="' . $url . '" title="' . $this->processTokens($params['filetitle'], $fileinfo, $params) . '" class="filetree-file-link">' . $name . '</a>';
    }

    return strtr($text, $replacements);
  }

}
