<?php

namespace GrofGraf\LaravelPDFMerger;

use setasign\Fpdi\Fpdi;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

class PDFMerger {
    /**
     * Access the filesystem on an oop base
     *
     * @var Filesystem
     */
    protected $filesystem = Filesystem::class;
    /**
     * Hold all the files which will be merged
     *
     * @var Collection
     */
    protected $files = Collection::class;
    /**
     * Holds every tmp file so they can be removed during the deconstruction
     *
     * @var Collection
     */
    protected $tmpFiles = Collection::class;
    /**
     * The actual PDF Service
     *
     * @var FPDI
     */
    protected $fpdi = Fpdi::class;
    /**
     * The final file name
     *
     * @var string
     */
    protected $fileName = 'undefined.pdf';
    /**
     * Construct and initialize a new instance
     * @param Filesystem $Filesystem
     */
    public function __construct(Filesystem $filesystem){
        $this->filesystem = $filesystem;
        $this->createDirectoryForTemporaryFiles();
        $this->fpdi = new Fpdi();
        $this->tmpFiles = collect([]);
        $this->files = collect([]);
    }
    /**
     * The class deconstructor method
     */
    public function __destruct() {
      $filesystem = $this->filesystem;
      $this->tmpFiles->each(function($filePath) use($filesystem){
          $filesystem->delete($filePath);
      });
    }
    /**
     * Initialize a new internal instance of FPDI in order to prevent any problems with shared resources
     * Please visit https://www.setasign.com/products/fpdi/manual/#p-159 for more information on this issue
     *
     * @return self
     */
    public function init(){
      return $this;
    }
    /**
     * Stream the merged PDF content
     *
     * @return string
     */
    public function inline(){
      return $this->fpdi->Output($this->fileName, 'I');
    }
    /**
     * Download the merged PDF content
     *
     * @return string
     */
    public function download(){
      return $this->fpdi->Output($this->fileName, 'D');
    }
    /**
     * Save the merged PDF content to the filesystem
     *
     * @return string
     */
    public function save($filePath = null){
      return $this->filesystem->put($filePath ? $filePath : $this->fileName, $this->string());
    }
    /**
     * Get the merged PDF content as binary string
     *
     * @return string
     */
    public function string(){
        return $this->fpdi->Output($this->fileName, 'S');
    }
    /**
     * Set the generated PDF fileName
     * @param string $fileName
     *
     * @return string
     */
    public function setFileName($fileName){
        $this->fileName = $fileName;
        return $this;
    }
    /**
     * Add a PDF for inclusion in the merge with a binary string. Pages should be formatted: 1,3,6, 12-16.
     * @param string $string
     * @param mixed $pages
     * @param mixed $orientation
     *
     * @return void
     */
    public function addPDFString($string, $pages = 'all', $orientation = null){
        $filePath = storage_path('tmp/'.str_random(16).'.pdf');
        $this->filesystem->put($filePath, $string);
        $this->tmpFiles->push($filePath);
        return $this->addPathToPDF($filePath, $pages, $orientation);
    }
    /**
     * Add a PDF for inclusion in the merge with a valid file path. Pages should be formatted: 1,3,6, 12-16.
     * @param string $filePath
     * @param string $pages
     * @param string $orientation
     *
     * @return self
     *
     * @throws \Exception if the given pages aren't correct
     */
    public function addPathToPDF($filePath, $pages = 'all', $orientation = null) {
        if (file_exists($filePath)) {
          $filePath = $this->convertPDFVersion($filePath);
          if (!is_array($pages) && strtolower($pages) != 'all') {
              throw new \Exception($filePath."'s pages could not be validated");
          }
          $this->files->push([
              'name'  => $filePath,
              'pages' => $pages,
              'orientation' => $orientation
          ]);
        } else {
            throw new \Exception("Could not locate PDF on '$filePath'");
        }
        return $this;
    }
    /**
     * Merges your provided PDFs and outputs to specified location.
     * @param string $orientation
     *
     * @return void
     *
     * @throws \Exception if there are now PDFs to merge
     */
    public function duplexMerge($orientation = 'P'){
      $this->merge($orientation, true);
    }

    public function merge($orientation = 'P', $duplex = false) {
      if ($this->files->count() == 0) {
          throw new \Exception("No PDFs to merge.");
      }
      $fpdi = $this->fpdi;
      $files = $this->files;
      foreach($files as $index => $file){
        $count = $fpdi->setSourceFile($file['name']);
        if($file['pages'] == 'all') {
          $pages = $count;
          for ($i = 1; $i <= $count; $i++) {
            $template   = $fpdi->importPage($i);
            $size       = $fpdi->getTemplateSize($template);
            $fpdi->AddPage($file['orientation']??$size['orientation'], [$size['width'], $size['height']]);
            $fpdi->useTemplate($template);
          }
        }else {
          $pages = count($file['pages']);
          foreach ($file['pages'] as $page) {
            if (!$template = $fpdi->importPage($page)) {
              throw new \Exception("Could not load page '$page' in PDF '".$file['name']."'. Check that the page exists.");
            }
            $size = $fpdi->getTemplateSize($template);
            $fpdi->AddPage($file['orientation'], [$size['width'], $size['height']]);
            $fpdi->useTemplate($template);
          }
        }
        if ($duplex && $pages % 2 && $index < (count($files) - 1)) {
          $fpdi->AddPage($file['orientation'], [$size['width'], $size['height']]);
        }
      }
    }

    /**
     * Converts PDF if version is above 1.4
     * @param string $filePath
     *
     * @return string
     */
    protected function convertPDFVersion($filePath){
      $pdf = fopen($filePath, "r");
      $first_line = fgets($pdf);
      fclose($pdf);
      //extract version number
      preg_match_all('!\d+!', $first_line, $matches);
      $pdfversion = implode('.', $matches[0]);
      if($pdfversion > "1.4"){
        $newFilePath = storage_path('tmp/' . str_random(16) . '.pdf');
        //execute shell script that converts PDF to correct version and saves it to tmp folder
        shell_exec('gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile="'. $newFilePath . '" "' . $filePath . '"');
        $this->tmpFiles->push($newFilePath);
        $filePath = $newFilePath;
      }
      //return correct file path
      return $filePath;
    }

    /**
     * Create a the temporary file directory if it doesn't exist.
     *
     * @return void
     */
    protected function createDirectoryForTemporaryFiles(): void
    {
        if (! $this->filesystem->isDirectory(storage_path('tmp'))) {
            $this->filesystem->makeDirectory(storage_path('tmp'));
        }
    }
}
