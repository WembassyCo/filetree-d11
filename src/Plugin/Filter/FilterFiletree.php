<?php

namespace Drupal\filetree\Plugin\Filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a filter to display file trees.
 *
 * @Filter(
 *   id = "filetree",
 *   title = @Translation("File Tree"),
 *   description = @Translation("Replaces [filetree dir=&quot;some-directory&quot;] with an inline list of files."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_HTML_RESTRICTOR
 * )
 */
class FilterFiletree extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The file URL generator.
   *
   * @var \Drupal\file\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new FilterFiletree.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RendererInterface $renderer, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->renderer = $renderer;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('renderer'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['folders'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed folder paths'),
      '#description' => $this->t('Enter one folder per line as paths which are allowed to be rendered as a list of files (relative to your file system path). The &quot;*&quot; character is a wildcard. Example paths are &quot;*&quot;, &quot;some-folder&quot;, and &quot;some-folder/*&quot;.'),
      '#default_value' => $this->settings['folders'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'folders' => '*',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    $output = '<p>' . $this->t('You may use [filetree dir=&quot;some-directory&quot;] to display a list of files inline.') . '</p>';
    if ($long) {
      $output .= '<p>' . $this->t('Additional options include &quot;multi&quot;, &quot;controls&quot;, &quot;absolute&quot;, &quot;exclude&quot;, &quot;dirname&quot;, &quot;dirtitle&quot;, &quot;filename&quot;, &quot;filetitle&quot;, and &quot;animation&quot;.') . '</p>';
      $output .= '<blockquote>[filetree dir=&quot;some-directory&quot; multi=&quot;false&quot; controls=&quot;false&quot; absolute=&quot;false&quot; exclude=&quot;CVS; directory1; directory2&quot; dirname=&quot;%basename&quot; dirtitle=&quot;Click to toggle this folder.&quot; filename=&quot;%basename&quot; filetitle=&quot;Click to download this %extension file.&quot; animation=&quot;false&quot;]</blockquote>';
      $output .= '<p>' . $this->t('Available tokens include: %filename, %basename, %extension, %size, %created, %modified.') . '</p>';
    }
    return ['#markup' => $output];
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    // Look for our special [filetree] token.
    if (!preg_match_all('/(?:<p>)?\[filetree\s*(.*?)\](?:<\/p>)?/s', $text, $matches)) {
      return new FilterProcessResult($text);
    }

    // Setup our default parameters.
    $default_params = [
      'dir' => NULL,
      'multi' => TRUE,
      'controls' => TRUE,
      'absolute' => TRUE,
      'exclude' => ['CVS'],
      'dirname' => '%filename',
      'dirtitle' => '%filename',
      'filename' => '%filename',
      'filetitle' => '%filename',
      'fileformat' => '%link',
      'animation' => TRUE,
    ];

    // The token might be present multiple times; loop through each instance.
    foreach ($matches[1] as $key => $passed_params) {
      // Load the defaults.
      $params[$key] = $default_params;

      // Parse the parameters (but only the valid ones).
      preg_match_all('/(\w*)=(?:"|&quot;)(.*?)(?:"|&quot;)/', $passed_params, $matches2);
      foreach ($matches2[1] as $param_key => $param_name) {
        if (in_array($param_name, array_keys($default_params))) {
          if (is_bool($default_params[$param_name])) {
            $params[$key][$param_name] = $matches2[2][$param_key] == "false" ? FALSE : (bool) $matches2[2][$param_key];
          }
          elseif ($param_name == 'exclude') {
            $params[$key][$param_name] = array_filter(array_map('trim', explode(';', $matches2[2][$param_key])));
          }
          else {
            $params[$key][$param_name] = $matches2[2][$param_key];
          }
        }
      }

      // Validate directory.
      if (!$this->validateDirectory($params[$key]['dir'])) {
        continue;
      }

      // Build URI and validate.
      $params[$key]['uri'] = 'public://' . $params[$key]['dir'];
      $path = \Drupal::service('file_system')->realpath($params[$key]['uri']);
      if (!$path || !is_dir($path)) {
        continue;
      }

      // Flatten exclude array.
      $params[$key]['exclude'] = implode("\n", $params[$key]['exclude']);

      // Convert params containing token values.
      foreach (['dirname', 'dirtitle', 'filename', 'filetitle', 'fileformat'] as $token_param) {
        $params[$key][$token_param] = preg_replace('/%(\w+)/', '[filetree:$1]', $params[$key][$token_param]);
      }

      // Render tree.
      $files = $this->listFiles($path, $params[$key]);
      $rendered = $this->themeFiletree($files, $params[$key]);

      // Replace token with rendered tree.
      $text = str_replace($matches[0][$key], $rendered, $text);
    }

    return new FilterProcessResult($text);
  }

  /**
   * Validates the directory path.
   *
   * @param string $dir
   *   The directory path to validate.
   *
   * @return bool
   *   TRUE if the directory is allowed, FALSE otherwise.
   */
  protected function validateDirectory($dir): bool {
    if (!$dir) {
      return FALSE;
    }

    $patterns = array_filter(array_map('trim', explode("\n", $this->settings['folders'])));
    foreach ($patterns as $pattern) {
      if ($pattern === '*' || fnmatch($pattern, $dir)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Recursively lists folders and files in this directory.
   *
   * @param string $dir
   *   The directory path.
   * @param array $params
   *   The parameters array.
   *
   * @return array
   *   A sorted list compatible with theme_item_list.
   */
  protected function listFiles($dir, array $params): array {
    $list = [];
    if (is_dir($dir) && $handle = opendir($dir)) {
      $folders = [];
      $files = [];

      // Parse .descript.ion file descriptions.
      $descriptions = $this->parseDescription($dir);

      while (FALSE !== ($filename = readdir($handle))) {
        // Exclude certain paths.
        if ($filename[0] != '.' && !$this->matchPath($filename, $params['exclude'])) {
          $filepath = "$dir/$filename";
          $token_args = [
            'filename' => $filename,
            'filepath' => $filepath,
            'descriptions' => $descriptions,
            'params' => $params,
          ];

          if (is_dir($filepath)) {
            $folders[$filename] = [
              'data' => $descriptions[$filename] ?? $this->tokenReplace($params['dirname'], $token_args),
              'children' => $this->listFiles($filepath, $params),
              'title' => $this->tokenReplace($params['dirtitle'], $token_args),
              'class' => ['folder'],
            ];
          }
          else {
            $files[$filename] = [
              'data' => $this->tokenReplace($params['fileformat'], $token_args),
              'title' => $this->tokenReplace($params['filetitle'], $token_args),
              'class' => [$this->getIcon(pathinfo($filename, PATHINFO_EXTENSION))],
            ];
          }
        }
      }
      closedir($handle);

      asort($folders);
      asort($files);

      $list = array_merge($folders, $files);
    }
    return $list;
  }

  /**
   * Parse .descript.ion file descriptions.
   *
   * @param string $dir
   *   The directory path.
   *
   * @return array
   *   An array of descriptions keyed by filename.
   */
  protected function parseDescription($dir): array {
    $descriptions = [];
    $description_file = "$dir/.descript.ion";
    if (is_readable($description_file) && $file = file($description_file)) {
      foreach ($file as $line) {
        $line = trim($line);
        if ($line == '' || strpos($line, '#') === 0) {
          continue;
        }
        $matches = [];
        if (strpos($line, '"') === 0) {
          preg_match('/^"([^"]+)"\s+(.*)$/', $line, $matches);
        }
        else {
          preg_match('/^(\S+)\s+(.*)$/', $line, $matches);
        }
        if (!empty($matches)) {
          list(, $name, $description) = $matches;
          if (isset($descriptions[$name])) {
            $descriptions[$name] .= ' ' . trim($description);
          }
          else {
            $descriptions[$name] = trim($description);
          }
        }
      }
    }
    return $descriptions;
  }

  /**
   * Determines which icon should be displayed, based on file extension.
   *
   * @param string $extension
   *   The file extension.
   *
   * @return string
   *   The icon type.
   */
  protected function getIcon($extension): string {
    $extension = strtolower($extension);
    $icon = 'file';
    $map = [
      'application' => ['exe'],
      'css' => ['css'],
      'db' => ['sql'],
      'doc' => ['doc', 'docx'],
      'film' => ['avi', 'mov'],
      'flash' => ['flv', 'swf'],
      'html' => ['htm', 'html'],
      'music' => ['mp3', 'aac'],
      'pdf' => ['pdf'],
      'php' => ['php'],
      'image' => ['jpg', 'jpeg', 'gif', 'png', 'bmp'],
      'ppt' => ['ppt'],
      'psd' => ['psd'],
      'script' => ['asp'],
      'txt' => ['txt'],
      'xls' => ['xls', 'xlsx'],
      'zip' => ['zip'],
    ];
    foreach ($map as $key => $values) {
      if (in_array($extension, $values)) {
        $icon = $key;
      }
    }
    return $icon;
  }

  /**
   * Recursively converts file list to renderable items.
   *
   * @param array $items
   *   The items array from listFiles().
   *
   * @return array
   *   Renderable items for theme_item_list.
   */
  protected function buildRenderItems(array $items): array {
    $render_items = [];
    foreach ($items as $item) {
      $render_item = [
        '#markup' => $item['data'],
      ];
      if (!empty($item['title'])) {
        $render_item['#attributes'] = ['title' => $item['title']];
      }
      if (!empty($item['class'])) {
        $render_item['#wrapper_attributes'] = ['class' => $item['class']];
      }
      if (isset($item['children'])) {
        $render_item['#children'] = $this->buildRenderItems($item['children']);
      }
      $render_items[] = $render_item;
    }
    return $render_items;
  }

  /**
   * Renders filetree.
   *
   * @param array $files
   *   The files array.
   * @param array $params
   *   The parameters array.
   *
   * @return string
   *   The rendered output.
   */
  protected function themeFiletree(array $files, array $params): string {
    $output = '';

    // Render controls.
    if ($params['multi'] && $params['controls']) {
      $has_folder = FALSE;
      foreach ($files as $file) {
        if (isset($file['children'])) {
          $has_folder = TRUE;
          break;
        }
      }
      if ($has_folder) {
        $controls = [
          ['#markup' => '<a href="#" class="expand">' . $this->t('expand all') . '</a>'],
          ['#markup' => '<a href="#" class="collapse">' . $this->t('collapse all') . '</a>'],
        ];
        $output .= $this->renderer->render([
          '#theme' => 'item_list',
          '#items' => $controls,
          '#title' => NULL,
          '#list_type' => 'ul',
          '#attributes' => ['class' => ['controls']],
        ]);
      }
    }

    // Convert files to renderable items.
    $render_files = $this->buildRenderItems($files);

    // Render files.
    $output .= $this->renderer->render([
      '#theme' => 'item_list',
      '#items' => $render_files,
      '#title' => NULL,
      '#list_type' => 'ul',
      '#attributes' => ['class' => ['files']],
    ]);

    // Generate classes and unique ID for wrapper div.
    $id = \Drupal\Component\Utility\Html::cleanCssIdentifier(uniqid('filetree-'));
    $classes = ['filetree'];
    if ($params['multi']) {
      $classes[] = 'multi';
    }
    if ($params['animation']) {
      $classes[] = 'filetree-animation';
    }

    return '<div id="' . $id . '" class="' . implode(' ', $classes) . '">' . $output . '</div>';
  }

  /**
   * Replace filetree tokens.
   *
   * @param string $text
   *   The text to replace.
   * @param array $args
   *   The token arguments.
   *
   * @return string
   *   The replaced text.
   */
  protected function tokenReplace($text, array $args): string {
    $filepath = $args['filepath'] ?? '';
    $filename = $args['filename'] ?? '';
    $descriptions = $args['descriptions'] ?? [];
    $params = $args['params'] ?? [];

    $replacements = [
      '[filetree:filename]' => pathinfo($filepath, PATHINFO_BASENAME),
      '[filetree:basename]' => pathinfo($filepath, PATHINFO_FILENAME),
      '[filetree:extension]' => pathinfo($filepath, PATHINFO_EXTENSION),
      '[filetree:size]' => format_size(filesize($filepath)),
      '[filetree:created]' => \Drupal::service('date.formatter')->format(filectime($filepath), 'filetree'),
      '[filetree:modified]' => \Drupal::service('date.formatter')->format(filemtime($filepath), 'filetree'),
      '[filetree:link]' => $this->buildFileLink($filepath, $filename, $descriptions, $params),
    ];

    return strtr($text, $replacements);
  }

  /**
   * Builds a file link.
   *
   * @param string $filepath
   *   The file path.
   * @param string $filename
   *   The file name.
   * @param array $descriptions
   *   The descriptions array.
   * @param array $params
   *   The parameters array.
   *
   * @return string
   *   The link markup.
   */
  protected function buildFileLink($filepath, $filename, array $descriptions, array $params): string {
    $name = $descriptions[$filename] ?? $this->tokenReplace($params['filename'], ['filepath' => $filepath, 'filename' => $filename]);
    $url = \Drupal::service('file_url_generator')->generateAbsoluteString($filepath);
    if (empty($params['absolute'])) {
      $url = str_replace($this->requestStack->getCurrentRequest()->getSchemeAndHttpHost() . '/', '', $url);
    }
    return '<a href="' . $url . '">' . $name . '</a>';
  }

  /**
   * Match a path against a pattern.
   *
   * @param string $path
   *   The path to match.
   * @param string $pattern
   *   The pattern to match against.
   *
   * @return bool
   *   TRUE if the path matches the pattern.
   */
  protected function matchPath($path, $pattern): bool {
    $patterns = array_filter(array_map('trim', explode("\n", $pattern)));
    foreach ($patterns as $p) {
      if (fnmatch($p, $path)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
