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
   * Maximum recursion depth to prevent memory exhaustion.
   */
  protected const MAX_DEPTH = 5;

  /**
   * Maximum number of files to process per directory.
   */
  protected const MAX_FILES_PER_DIR = 100;

  /**
   * Maximum total files to process.
   */
  protected const MAX_TOTAL_FILES = 500;

  /**
   * Current file count during processing.
   */
  protected int $fileCount = 0;

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
   * List files in a directory recursively with safety limits.
   *
   * @param string $uri
   *   The URI of the directory to scan.
   * @param array $params
   *   Configuration parameters.
   * @param int $depth
   *   Current recursion depth (internal use).
   *
   * @return array
   *   Array of file information.
   */
  public function listFiles(string $uri, array $params, int $depth = 0): array {
    // Safety check: max depth exceeded
    if ($depth > self::MAX_DEPTH) {
      return [];
    }

    // Safety check: max total files reached
    if ($this->fileCount >= self::MAX_TOTAL_FILES) {
      return [];
    }

    $files = [];
    
    if (!is_dir($uri) || !is_readable($uri)) {
      return $files;
    }

    try {
      $iterator = new \DirectoryIterator($uri);
      $fileCountInDir = 0;
      
      foreach ($iterator as $fileinfo) {
        // Safety check: max files per directory
        if ($fileCountInDir >= self::MAX_FILES_PER_DIR) {
          break;
        }

        // Safety check: max total files
        if ($this->fileCount >= self::MAX_TOTAL_FILES) {
          break;
        }

        // Skip hidden files and dot entries
        if ($fileinfo->isDot()) {
          continue;
        }
        
        $basename = $fileinfo->getBasename();
        
        // Check exclude patterns
        if (!empty($params['exclude'])) {
          $excluded = FALSE;
          foreach ($params['exclude'] as $exclude) {
            if ($this->matchPattern($basename, $exclude)) {
              $excluded = TRUE;
              break;
            }
          }
          if ($excluded) {
            continue;
          }
        }

        $filepath = $fileinfo->getPathname();
        
        if ($fileinfo->isDir()) {
          // Recursively scan subdirectory with increased depth
          $children = $this->listFiles($filepath, $params, $depth + 1);
          
          $files[$basename] = [
            'type' => 'directory',
            'name' => $basename,
            'path' => $filepath,
            'children' => $children,
          ];
        } else {
          // Skip large files (over 100MB) to save memory
          $fileSize = $fileinfo->getSize();
          if ($fileSize > 104857600) { // 100MB
            $files[$basename] = [
              'type' => 'file',
              'name' => $basename,
              'path' => $filepath,
              'filename' => $fileinfo->getFilename(),
              'extension' => $fileinfo->getExtension(),
              'size' => $fileSize,
              'created' => $fileinfo->getCTime(),
              'modified' => $fileinfo->getMTime(),
            ];
          } else {
            $files[$basename] = [
              'type' => 'file',
              'name' => $basename,
              'path' => $filepath,
              'filename' => $fileinfo->getFilename(),
              'extension' => $fileinfo->getExtension(),
              'size' => $fileSize,
              'created' => $fileinfo->getCTime(),
              'modified' => $fileinfo->getMTime(),
            ];
          }
          
          $this->fileCount++;
        }

        $fileCountInDir++;
      }

      // Sort files: directories first, then alphabetically
      uksort($files, function ($a, $b) use ($files) {
        $a_is_dir = isset($files[$a]['type']) && $files[$a]['type'] === 'directory';
        $b_is_dir = isset($files[$b]['type']) && $files[$b]['type'] === 'directory';
        
        if ($a_is_dir !== $b_is_dir) {
          return $a_is_dir ? -1 : 1;
        }
        
        return strnatcasecmp($a, $b);
      });

    } catch (\Exception $e) {
      // Return empty array on error (permissions, etc.)
      return [];
    }

    return $files;
  }

  /**
   * Reset file count before a new scan.
   */
  public function resetFileCount(): void {
    $this->fileCount = 0;
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
      '[filetree:filename]' => $fileinfo['filename'] ?? $fileinfo['name'] ?? '',
      '[filetree:basename]' => pathinfo($fileinfo['name'] ?? '', PATHINFO_FILENAME),
      '[filetree:extension]' => $fileinfo['extension'] ?? pathinfo($fileinfo['name'] ?? '', PATHINFO_EXTENSION),
      '[filetree:size]' => isset($fileinfo['size']) ? $this->formatSize($fileinfo['size']) : '',
      '[filetree:created]' => isset($fileinfo['created']) ? $this->formatDate($fileinfo['created']) : '',
      '[filetree:modified]' => isset($fileinfo['modified']) ? $this->formatDate($fileinfo['modified']) : '',
    ];

    // Generate link token
    if (isset($fileinfo['path']) && isset($fileinfo['type']) && $fileinfo['type'] === 'file') {
      $name = $this->processTokens($params['filename'] ?? '%filename', $fileinfo, $params);
      $url = $params['absolute'] ?? TRUE
        ? $this->fileUrlGenerator->generateAbsoluteString($fileinfo['path'])
        : $this->fileUrlGenerator->generateString($fileinfo['path']);
      
      $replacements['[filetree:link]'] = '<a href="' . $url . '" title="' . $this->processTokens($params['filetitle'] ?? '%filename', $fileinfo, $params) . '" class="filetree-file-link">' . $name . '</a>';
    }

    return strtr($text, $replacements);
  }

}
