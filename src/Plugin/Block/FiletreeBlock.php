<?php

declare(strict_types=1);

namespace Drupal\filetree\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\filetree\FiletreeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a File Tree block.
 */
#[Block(
  id: "filetree_block",
  admin_label: new TranslatableMarkup("File Tree"),
  category: new TranslatableMarkup("Content")
)]
class FiletreeBlock extends BlockBase {

  /**
   * The file URL generator.
   */
  protected ?FileUrlGeneratorInterface $fileUrlGenerator = NULL;

  /**
   * The filetree service.
   */
  protected ?FiletreeService $filetreeService = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->filetreeService = $container->get('filetree.service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'folder_path' => '',
      'multi' => TRUE,
      'controls' => TRUE,
      'absolute' => TRUE,
      'exclude' => 'CVS',
      'animation' => TRUE,
      'dirname' => '%filename',
      'dirtitle' => '%filename',
      'filename' => '%filename',
      'filetitle' => '%filename',
      'fileformat' => '%link',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->configuration;

    $form['folder_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Folder Path'),
      '#description' => $this->t('Enter the folder path relative to the files directory (e.g., "documents" or "downloads/pdfs"). Use "*" for root files directory.'),
      '#default_value' => $config['folder_path'],
      '#required' => TRUE,
    ];

    $form['display_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Display Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['display_settings']['multi'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow multiple folders to be open'),
      '#default_value' => $config['multi'],
    ];

    $form['display_settings']['controls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show expand/collapse all controls'),
      '#default_value' => $config['controls'],
    ];

    $form['display_settings']['absolute'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use absolute URLs'),
      '#default_value' => $config['absolute'],
    ];

    $form['display_settings']['animation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable animations'),
      '#default_value' => $config['animation'],
    ];

    $form['display_settings']['exclude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Exclude patterns'),
      '#description' => $this->t('Semicolon-separated list of files/folders to exclude (e.g., "CVS; .git; hidden")'),
      '#default_value' => $config['exclude'],
    ];

    $form['token_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Token Settings'),
      '#description' => $this->t('Available tokens: %filename, %basename, %extension, %size, %created, %modified, %link'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['token_settings']['dirname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory name format'),
      '#default_value' => $config['dirname'],
    ];

    $form['token_settings']['dirtitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Directory title format'),
      '#default_value' => $config['dirtitle'],
    ];

    $form['token_settings']['filename'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filename format'),
      '#default_value' => $config['filename'],
    ];

    $form['token_settings']['filetitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File title format'),
      '#default_value' => $config['filetitle'],
    ];

    $form['token_settings']['fileformat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File format'),
      '#description' => $this->t('How each file appears in the list'),
      '#default_value' => $config['fileformat'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    parent::blockSubmit($form, $form_state);
    
    $values = $form_state->getValues();
    $this->configuration['folder_path'] = $values['folder_path'];
    $this->configuration['multi'] = (bool) $values['display_settings']['multi'];
    $this->configuration['controls'] = (bool) $values['display_settings']['controls'];
    $this->configuration['absolute'] = (bool) $values['display_settings']['absolute'];
    $this->configuration['animation'] = (bool) $values['display_settings']['animation'];
    $this->configuration['exclude'] = $values['display_settings']['exclude'];
    $this->configuration['dirname'] = $values['token_settings']['dirname'];
    $this->configuration['dirtitle'] = $values['token_settings']['dirtitle'];
    $this->configuration['filename'] = $values['token_settings']['filename'];
    $this->configuration['filetitle'] = $values['token_settings']['filetitle'];
    $this->configuration['fileformat'] = $values['token_settings']['fileformat'];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->configuration;
    
    // Ensure services are available (fallback to container if create() wasn't called)
    if ($this->filetreeService === NULL) {
      $this->filetreeService = \Drupal::service('filetree.service');
    }
    if ($this->fileUrlGenerator === NULL) {
      $this->fileUrlGenerator = \Drupal::service('file_url_generator');
    }
    
    if (empty($config['folder_path'])) {
      return [
        '#markup' => $this->t('Please configure the folder path in block settings.'),
      ];
    }

    try {
      // Build the params array for the service
      $params = [
        'dir' => $config['folder_path'],
        'multi' => $config['multi'] ?? TRUE,
        'controls' => $config['controls'] ?? TRUE,
        'absolute' => $config['absolute'] ?? TRUE,
        'exclude' => array_filter(array_map('trim', explode(';', $config['exclude'] ?? 'CVS'))),
        'dirname' => $config['dirname'] ?? '%filename',
        'dirtitle' => $config['dirtitle'] ?? '%filename',
        'filename' => $config['filename'] ?? '%filename',
        'filetitle' => $config['filetitle'] ?? '%filename',
        'fileformat' => $config['fileformat'] ?? '%link',
      ];

      // Convert tokens in format strings
      foreach (['dirname', 'dirtitle', 'filename', 'filetitle', 'fileformat'] as $token_param) {
        $params[$token_param] = str_replace('%', '[filetree:', $params[$token_param]) . ']';
      }

      // Build URI from folder path
      $scheme = \Drupal::config('system.file')->get('default_scheme');
      $params['uri'] = $scheme . '://' . $config['folder_path'];

      // Reset file count before scan
      $this->filetreeService->resetFileCount();

      // Get file list from service
      $files = $this->filetreeService->listFiles($params['uri'], $params);

      if (empty($files)) {
        return [
          '#markup' => $this->t('No files found in the specified folder.'),
        ];
      }

      $build = [
        '#theme' => 'filetree',
        '#files' => $files,
        '#params' => $params,
        '#attached' => [
          'library' => ['filetree/filetree'],
        ],
      ];

      return $build;

    } catch (\Exception $e) {
      \Drupal::logger('filetree')->error('Error building filetree block: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('Error loading file tree. The folder may contain too many files or be inaccessible.'),
      ];
    }
  }

}
