<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Component\Utility\Bytes;
use Drupal\file\FileInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Applies file indexability rules and extraction limits.
 */
class ExtractFileValidator {

  /**
   * File extensions excluded from extraction by default.
   */
  public const DEFAULT_EXCLUDED_EXTENSIONS = 'aif art avi bmp gif ico mov oga ogg ogv png psd ra ram rgb flv';

  public function __construct(
    private readonly MimeTypeGuesserInterface $mimeTypeGuesser,
  ) {}

  /**
   * Converts excluded extensions to a de-duplicated list of MIME types.
   *
   * @param string[] $extensions
   *   Extensions to convert, or an empty array to use the defaults.
   *
   * @return string[]
   *   The MIME types in first-seen order.
   */
  public function getExcludedMimes(array $extensions): array {
    if ($extensions === []) {
      $extensions = explode(' ', self::DEFAULT_EXCLUDED_EXTENSIONS);
    }

    $mimes = [];
    foreach ($extensions as $extension) {
      $mime = $this->mimeTypeGuesser->guessMimeType('dummy.' . $extension);
      if ($mime !== NULL && !in_array($mime, $mimes, TRUE)) {
        $mimes[] = $mime;
      }
    }

    return $mimes;
  }

  /**
   * Determines whether a file is within the configured size limit.
   */
  public function isFileSizeAllowed(FileInterface $file, string $maxFilesize): bool {
    if ($maxFilesize === '' || $maxFilesize === '0') {
      return TRUE;
    }

    return $file->getSize() <= Bytes::toNumber($maxFilesize);
  }

  /**
   * Determines whether the configured private-file policy allows a file.
   */
  public function isPrivateFileAllowed(FileInterface $file, bool $excludedPrivate): bool {
    return !$excludedPrivate || !str_starts_with($file->getFileUri(), 'private://');
  }

  /**
   * Limits a list of file IDs to the configured number.
   *
   * @param array $fileIds
   *   File IDs in field order.
   * @param int $numberIndexed
   *   Maximum number to retain, or zero for no limit.
   *
   * @return array
   *   The retained file IDs as a list.
   */
  public function limitToAllowedNumber(array $fileIds, int $numberIndexed): array {
    return $numberIndexed === 0
      ? $fileIds
      : array_values(array_slice($fileIds, 0, $numberIndexed));
  }

  /**
   * Truncates extracted text to a multibyte-safe byte limit.
   */
  public function limitBytes(string $text, string $maxBytes): string {
    if ($maxBytes === '' || $maxBytes === '0') {
      return $text;
    }

    return mb_strcut($text, 0, (int) Bytes::toNumber($maxBytes), 'UTF-8');
  }

  /**
   * Determines whether a file passes every indexability rule.
   *
   * @param string[] $excludedMimes
   *   MIME types excluded from extraction.
   */
  public function isFileIndexable(FileInterface $file, array $excludedMimes, string $maxFilesize, bool $excludedPrivate): bool {
    return !in_array($file->getMimeType(), $excludedMimes, TRUE)
      && $this->isFileSizeAllowed($file, $maxFilesize)
      && $this->isPrivateFileAllowed($file, $excludedPrivate);
  }

}
