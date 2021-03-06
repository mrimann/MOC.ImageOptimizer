<?php
namespace MOC\ImageOptimizer\Aspects;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Aop\JoinPointInterface;

/**
 * @Flow\Scope("singleton")
 * @Flow\Aspect
 */
class ResourcePublisherAspect {

	/**
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 * @Flow\Inject
	 */
	protected $systemLogger;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Package\PackageManagerInterface
	 */
	protected $packageManager;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * Flow 2.x
	 *
	 * @Flow\AfterReturning("method(TYPO3\Flow\Resource\Publishing\FileSystemPublishingTarget->mirrorFile())")
	 * @param \TYPO3\Flow\Aop\JoinPointInterface $joinPoint The current join point
	 * @return void
	 */
	public function optimizeMirroredFile(JoinPointInterface $joinPoint) {
		$this->optimizeFile($joinPoint->getMethodArgument('targetPathAndFilename'));
	}

	/**
	 * Flow 3.x
	 *
	 * @Flow\AfterReturning("method(TYPO3\Flow\Resource\Target\FileSystemSymlinkTarget->publishFile())")
	 * @Flow\AfterReturning("method(TYPO3\Flow\Resource\Target\FileSystemTarget->publishFile())")
	 * @param \TYPO3\Flow\Aop\JoinPointInterface $joinPoint The current join point
	 * @return void
	 */
	public function optimizePublishedFile(JoinPointInterface $joinPoint) {
		$this->optimizeFile($joinPoint->getMethodArgument('relativeTargetPathAndFilename'));
	}

	/**
	 * @param string $pathAndFilename
	 * @return void
	 * @throws \Exception
	 */
	public function optimizeFile($pathAndFilename) {
		$imageType = exif_imagetype($pathAndFilename);
		$fileExtension = strtolower(pathinfo($pathAndFilename, PATHINFO_EXTENSION));
		if (($imageType !== FALSE && !in_array($imageType, [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_GIF], TRUE)) || ($imageType === FALSE && $fileExtension !== 'svg')) {
			return;
		}

		$useGlobalBinary = $this->settings['useGlobalBinary'];
		$binaryRootPath = 'Private/Library/node_modules/';
		$file = escapeshellarg(realpath($pathAndFilename));
		if ($imageType !== FALSE) {
			switch ($imageType) {
				case \IMAGETYPE_JPEG:
					if ($this->settings['formats']['jpg']['enabled'] === FALSE) {
						return;
					}
					$library = 'jpegtran';
					$arguments = sprintf('-copy none -optimize %s -outfile %s %s', $this->settings['formats']['jpg']['progressive'] === TRUE ? '-progressive' : '', $file, $file);
					if ($this->settings['formats']['jpg']['useGlobalBinary'] === TRUE) {
						$useGlobalBinary = TRUE;
					}
					break;
				case \IMAGETYPE_PNG:
					if ($this->settings['formats']['png']['enabled'] === FALSE) {
						return;
					}
					$library = 'optipng';
					$arguments = sprintf('-o%u -strip all -out %s %s', $this->settings['formats']['png']['optimizationLevel'], $file, $file);
					if ($this->settings['formats']['png']['useGlobalBinary'] === TRUE) {
						$useGlobalBinary = TRUE;
					}
					break;
				case \IMAGETYPE_GIF:
					if ($this->settings['formats']['gif']['enabled'] === FALSE) {
						return;
					}
					$library = 'gifsicle';
					$arguments = sprintf('--batch -O%u %s ', $this->settings['formats']['gif']['optimizationLevel'], $file);
					if ($this->settings['formats']['gif']['useGlobalBinary'] === TRUE) {
						$useGlobalBinary = TRUE;
					}
					break;
			}
			$binaryPath = sprintf('%1$s/vendor/%1$s', $library);
		} else {
			if ($this->settings['formats']['svg']['enabled'] === FALSE) {
				return;
			}
			$library = 'svgo';
			$binaryPath = sprintf('%1$s/bin/%1$s', $library);
			$arguments = sprintf('%s %s', $this->settings['formats']['svg']['pretty'] === TRUE ? '--pretty' : '', $file);
			if ($this->settings['formats']['svg']['useGlobalBinary'] === TRUE) {
				$useGlobalBinary = TRUE;
			}
		}
		$binaryPath = $useGlobalBinary === TRUE ? $library : $this->packageManager->getPackageOfObject($this)->getResourcesPath() . $binaryRootPath . $binaryPath;
		$cmd = escapeshellcmd($binaryPath) . ' ' . $arguments;
		$output = [];
		exec($cmd, $output, $result);
		$this->systemLogger->log($cmd . ' (' . $result . ')', LOG_INFO, $output);
	}

}