<?php

namespace APP\plugins\importexport\copernicusNative;

use APP\template\TemplateManager;
use PKP\file\FileManager;
use PKP\plugins\ImportExportPlugin;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CopernicusNativePlugin extends ImportExportPlugin
{
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            $this->addLocaleData();
        }
        return $success;
    }

    public function getName(): string
    {
        return 'CopernicusNativePlugin';
    }

    public function getDisplayName(): string
    {
        return __('plugins.importexport.copernicusNative.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.importexport.copernicusNative.description');
    }

    public function getPluginSettingsPrefix(): string
    {
        return 'copernicusNative';
    }

    public function display($args, $request)
    {
        parent::display($args, $request);
        $templateMgr = TemplateManager::getManager($request);

        $op = array_shift($args);
        if (!$op && $request->getUserVar('selectedIssues')) {
            $op = 'exportIssues';
        }

        switch ($op) {
            case 'index':
            case '':
                $templateMgr->assign([
                    'pageTitle' => $this->getDisplayName(),
                ]);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;

            case 'exportIssues':
                try {
                $selectedIssues = (array) $request->getUserVar('selectedIssues');
                $issueIds = array_values(array_filter(array_map('intval', $selectedIssues)));

                if (empty($issueIds)) {
                    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ici-import>\n  <!-- Please select at least one issue. -->\n</ici-import>\n";
                } else {
                    $errors = [];
                    $journal = $request->getContext();
                    $journalId = $journal->getId();
                    $issn = $journal->getData('onlineIssn') ?: '';
                    
                    $dom = new \DOMDocument('1.0', 'UTF-8');
                    $dom->formatOutput = true;
                    
                    $root = $dom->createElement('ici-import');
                    $dom->appendChild($root);
                    
                    $journalEl = $dom->createElement('journal');
                    $journalEl->setAttribute('issn', $issn);
                    $root->appendChild($journalEl);

                    foreach ($issueIds as $issueId) {
                        $issue = \APP\facades\Repo::issue()->get($issueId);
                        if (!$issue) {
                            $errors[] = "Issue not found: {$issueId}";
                            continue;
                        }
                        if ($issue->getJournalId() != $journalId) {
                            $errors[] = "Issue {$issueId} does not belong to this journal.";
                            continue;
                        }

                        $volume = (string)$issue->getVolume();
                        $number = (string)$issue->getNumber();
                        $year = (string)$issue->getYear();
                        $issueDate = $issue->getDatePublished() ? date('Y-m-d', strtotime($issue->getDatePublished())) : '';
                        
                        if (trim($volume) === '') $errors[] = "Issue {$issueId}: volume is empty";
                        if (trim($number) === '') $errors[] = "Issue {$issueId}: number is empty";
                        if (trim($year) === '') $errors[] = "Issue {$issueId}: year is empty";
                        if (trim($issueDate) === '') $errors[] = "Issue {$issueId}: publicationDate is empty";
                        if (trim($issn) === '') $errors[] = "Issue {$issueId}: onlineIssn is empty";

                        $submissions = \APP\facades\Repo::submission()->getCollector()
                            ->filterByContextIds([$journalId])
                            ->filterByIssueIds([$issueId])
                            ->getMany();
                            
                        $issueEl = $dom->createElement('issue');
                        $issueEl->setAttribute('number', $number);
                        $issueEl->setAttribute('volume', $volume);
                        $issueEl->setAttribute('year', $year);
                        $issueEl->setAttribute('publicationDate', $issueDate);
                        $issueEl->setAttribute('numberOfArticles', (string)$submissions->count());
                        $root->appendChild($issueEl);

                        foreach ($submissions as $submission) {
                            $publication = $submission->getCurrentPublication();
                            $pubId = $publication->getId();
                            
                            $articleEl = $dom->createElement('article');
                            $issueEl->appendChild($articleEl);
                            
                            $articleEl->appendChild($dom->createElement('type', 'ORIGINAL_ARTICLE'));
                            
                            $doi = $publication->getDoi() ?: '';
                            $datePublished = $publication->getData('datePublished') ? date('Y-m-d', strtotime($publication->getData('datePublished'))) : '';
                            $pages = $publication->getData('pages') ?: '';
                            $licenseUrl = $publication->getData('licenseUrl') ?: '';
                            
                            $pageFrom = '';
                            $pageTo = '';
                            if (preg_match('/^\s*(\d+)\s*[-–—]\s*(\d+)\s*$/', $pages, $m)) {
                                $pageFrom = $m[1];
                                $pageTo = $m[2];
                            } elseif (preg_match('/^\s*(\d+)\s*$/', $pages, $m)) {
                                $pageFrom = $m[1];
                                $pageTo = $m[1];
                            }
                            
                            $galleyId = '';
                            $galleys = $publication->getData('galleys');
                            if ($galleys) {
                                foreach ($galleys as $galley) {
                                    if ($galley->getLabel() === 'PDF') {
                                        $galleyId = $galley->getId();
                                        break;
                                    }
                                }
                            }
                            
                            $articleLabel = "Issue {$issueId} / submission {$submission->getId()}";
                            if (trim($doi) === '') $errors[] = "{$articleLabel}: DOI is empty";
                            if (trim($datePublished) === '') $errors[] = "{$articleLabel}: publicationDate is empty";
                            if (trim($galleyId) === '') $errors[] = "{$articleLabel}: PDF galley missing";
                            if (!ctype_digit($pageFrom) || !ctype_digit($pageTo)) $errors[] = "{$articleLabel}: invalid pages ({$pages})";
                            if (trim($licenseUrl) === '') $errors[] = "{$articleLabel}: licenseUrl empty";
                            
                            $keywordsEn = $publication->getData('keywords', 'en') ?: [];
                            $keywordsId = $publication->getData('keywords', 'id') ?: [];
                            
                            foreach (['en', 'id'] as $lang) {
                                $lv = $dom->createElement('languageVersion');
                                $lv->setAttribute('language', $lang);
                                $articleEl->appendChild($lv);
                                
                                $title = $publication->getData('title', $lang) ?: '';
                                $subtitle = $publication->getData('subtitle', $lang) ?: '';
                                if ($subtitle) {
                                    $title .= ': ' . $subtitle;
                                }
                                $abstract = $publication->getData('abstract', $lang) ?: '';
                                
                                $cleanTitle = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_XML1, 'UTF-8');
                                $cleanAbstract = html_entity_decode(strip_tags($abstract), ENT_QUOTES | ENT_XML1, 'UTF-8');
                                
                                if (trim($cleanTitle) === '') $errors[] = "{$articleLabel}: title {$lang} empty";
                                if (trim($cleanAbstract) === '') $errors[] = "{$articleLabel}: abstract {$lang} empty";
                                
                                $lv->appendChild($dom->createElement('title', htmlspecialchars($cleanTitle, ENT_XML1, 'UTF-8')));
                                $lv->appendChild($dom->createElement('abstract', htmlspecialchars($cleanAbstract, ENT_XML1, 'UTF-8')));
                                
                                $pdfUrl = $request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $journal->getPath(), 'article', 'download', [$submission->getId(), $galleyId]);
                                $articleUrl = $request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $journal->getPath(), 'article', 'view', [$submission->getId()]);
                                
                                $lv->appendChild($dom->createElement('pdfFileUrl', $pdfUrl));
                                $lv->appendChild($dom->createElement('articleUrl', $articleUrl));
                                $lv->appendChild($dom->createElement('publicationDate', $datePublished));
                                $lv->appendChild($dom->createElement('pageFrom', $pageFrom));
                                $lv->appendChild($dom->createElement('pageTo', $pageTo));
                                $lv->appendChild($dom->createElement('doi', $doi));
                                
                                $langKeywords = ($lang === 'en') ? $keywordsEn : $keywordsId;
                                if (empty($langKeywords)) {
                                    $langKeywords = !empty($keywordsEn) ? $keywordsEn : (!empty($keywordsId) ? $keywordsId : []);
                                }
                                
                                if (empty($langKeywords)) $errors[] = "{$articleLabel}: keywords {$lang} empty";
                                
                                $kwEl = $dom->createElement('keywords');
                                $lv->appendChild($kwEl);
                                foreach ($langKeywords as $kw) {
                                    $kwString = is_array($kw) ? (isset($kw['keyword']) ? $kw['keyword'] : reset($kw)) : $kw;
                                    $kwString = (string)$kwString;
                                    $cleanKw = html_entity_decode(strip_tags($kwString), ENT_QUOTES | ENT_XML1, 'UTF-8');
                                    $kwEl->appendChild($dom->createElement('keyword', htmlspecialchars($cleanKw, ENT_XML1, 'UTF-8')));
                                }
                                
                                $lic = $dom->createElement('license', htmlspecialchars($licenseUrl, ENT_XML1, 'UTF-8'));
                                $lic->setAttribute('type', 'CC BY-SA');
                                $lv->appendChild($lic);
                            }
                            
                            $authorsEl = $dom->createElement('authors');
                            $articleEl->appendChild($authorsEl);
                            
                            $authors = $publication->getData('authors');
                            $order = 1;
                            if ($authors) {
                                foreach ($authors as $author) {
                                    $au = $dom->createElement('author');
                                    $authorsEl->appendChild($au);
                                    
                                    $givenName = $author->getLocalizedGivenName() ?: '';
                                    $familyName = $author->getLocalizedFamilyName() ?: '';
                                    $email = $author->getEmail() ?: '';
                                    $affiliation = html_entity_decode(strip_tags($author->getLocalizedData('affiliation') ?: ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
                                    $country = $author->getCountry() ?: '';
                                    $role = ($author->getId() == $publication->getData('primaryContactId')) ? 'LEAD_AUTHOR' : 'AUTHOR';
                                    
                                    $au->appendChild($dom->createElement('name', htmlspecialchars($givenName, ENT_XML1, 'UTF-8')));
                                    $au->appendChild($dom->createElement('surname', htmlspecialchars($familyName, ENT_XML1, 'UTF-8')));
                                    $au->appendChild($dom->createElement('email', htmlspecialchars($email, ENT_XML1, 'UTF-8')));
                                    $au->appendChild($dom->createElement('order', (string)$order));
                                    $au->appendChild($dom->createElement('instituteAffiliation', htmlspecialchars($affiliation, ENT_XML1, 'UTF-8')));
                                    $au->appendChild($dom->createElement('country', htmlspecialchars($country, ENT_XML1, 'UTF-8')));
                                    $au->appendChild($dom->createElement('role', $role));
                                    
                                    $order++;
                                }
                            }
                            
                            $citationsRaw = $publication->getData('citationsRaw');
                            $citations = array_filter(array_map('trim', explode("\n", $citationsRaw ?: '')));
                            
                            if (empty($citations)) {
                                $errors[] = "{$articleLabel}: references empty";
                            } else {
                                $refsEl = $dom->createElement('references');
                                $articleEl->appendChild($refsEl);
                                $refOrder = 1;
                                foreach ($citations as $cit) {
                                    $ref = $dom->createElement('reference');
                                    $refsEl->appendChild($ref);
                                    
                                    $cleanCit = html_entity_decode(strip_tags($cit), ENT_QUOTES | ENT_XML1, 'UTF-8');
                                    $ref->appendChild($dom->createElement('unparsedContent', htmlspecialchars($cleanCit, ENT_XML1, 'UTF-8')));
                                    $ref->appendChild($dom->createElement('order', (string)$refOrder));
                                    $ref->appendChild($dom->createElement('doi', ''));
                                    $refOrder++;
                                }
                            }
                        }
                    }

                    if (!empty($errors)) {
                        header('Content-Type: text/html; charset=utf-8');
                        echo '<!doctype html><html><head><meta charset="utf-8">';
                        echo '<title>Copernicus Export Failed</title>';
                        echo '<style>
                            body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:40px;line-height:1.5;background:#f8fafc;color:#0f172a}
                            .box{max-width:980px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
                            h1{margin-top:0;color:#b91c1c;font-size:24px}
                            .meta{background:#f1f5f9;border-radius:8px;padding:12px;margin:16px 0}
                            ul{padding-left:24px}
                            li{margin:6px 0}
                            code{background:#f1f5f9;padding:2px 6px;border-radius:4px}
                            .hint{margin-top:20px;color:#475569}
                        </style>';
                        echo '</head><body><div class="box">';
                        echo '<h1>Copernicus export gagal</h1>';
                        echo '<p>XML tidak dibuat karena metadata issue/artikel belum lengkap.</p>';
                        echo '<div class="meta">';
                        echo '<div><strong>Issue ID:</strong> ' . htmlspecialchars(implode(',', $issueIds), ENT_QUOTES, 'UTF-8') . '</div>';
                        echo '</div>';
                        echo '<h2>Daftar masalah</h2><ul>';
                        foreach (array_unique($errors) as $err) {
                            echo '<li>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</li>';
                        }
                        echo '</ul>';
                        echo '<p class="hint">Perbaiki metadata di OJS, lalu ulangi export.</p>';
                        echo '</div></body></html>';
                        exit;
                    }

                    $xml = $dom->saveXML();
                }

                $fileManager = new FileManager();
                $exportFileName = $this->getExportFileName($this->getExportPath(), 'copernicus-issues', $request->getContext());
                $fileManager->writeFile($exportFileName, $xml);
                file_put_contents('/home/arissupriy/stai/ejournal.staialanwar.ac.id/httpdocs/sample_copernicus.xml', $xml);
                $fileManager->downloadByPath($exportFileName);
                $fileManager->deleteByPath($exportFileName);
                } catch (\Throwable $e) {
                    echo "Fatal Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
                    exit;
                }
                break;

            default:
                throw new NotFoundHttpException();
        }
    }

    public function executeCLI($scriptName, &$args)
    {
        $this->usage($scriptName);
    }

    public function usage($scriptName)
    {
        echo "Copernicus XML Export (Native)\n";
        echo "Usage: php {$scriptName} CopernicusNativePlugin\n";
    }
}
