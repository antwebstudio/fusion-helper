<?php
namespace Ant\FusionHelper\Helpers;

use Corcel\Model\Post;
use Fusion\Models\File;
use Fusion\Models\Field;
use Fusion\Models\Matrix;
use Fusion\Models\Section;
use Fusion\Models\Taxonomy;
use Illuminate\Support\Str;
use Corcel\Model\Attachment;
use Fusion\Models\Directory;
use Fusion\Services\Builders;
use Illuminate\Support\Facades\DB;
use Ant\FusionHelper\FusionImporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\DomCrawler\Crawler;
use Corcel\WooCommerce\Model\Product as WooCommerceProduct;

class Fusion extends \Ant\FusionHelper\FusionImporter {

    protected static $mappedIds = [];

    public static function createCollectionEntry($matrixHandle, $attributes = [])
    {
        $matrix = Matrix::where('handle', $matrixHandle)->first();
        $entry = Builders\Matrix::resolve($matrix->handle);
        $validator = Validator::make($attributes, static::getRulesForMatrix($matrix));
        
        $entry = $entry->create(array_merge($validator->validated(), [
            'matrix_id' => $matrix->id,
        ]));

        // persist relationships..
        foreach ($entry->matrix->blueprint->relationships() as $relationship) {
            $relationship->type()->persistRelationship($entry, $relationship, $attributes[$relationship->handle] ?? null);
        }
        
        return $entry;
    }

    public static function updateCollectionEntry($matrixHandle, $entryId, $attributes = [])
    {
        $matrix = Matrix::where('handle', $matrixHandle)->first();
        $entry = Builders\Matrix::resolve($matrix->handle);
        $entry  = $entry->withoutGlobalScopes()->findOrFail($entryId);
        $attributes['matrix_id'] = $matrix->id;
        
        $validator = Validator::make($attributes, static::getRulesForMatrix($matrix));

        $entry->update($validator->validated());

        // persist relationships..
        foreach ($entry->matrix->blueprint->relationships() as $relationship) {
            $relationship->type()->persistRelationship($entry, $relationship);
        }
    }

    public static function getRulesForMatrix($matrix)
    {
        $rules = [
            'matrix_id'  => 'required|integer',
            'name' => 'nullable',
        ];

        $rules += $matrix->fields->flatMap(function ($field) use($matrix) {
            return $field->type()->rules($field, $matrix->{$field->handle});
        })->toArray();

        return $rules;
    }

    public static function deleteFieldForExtension($handle, $fieldHandle) {
        $extension = \Fusion\Models\Extension::where('handle', $handle)->firstOrFail();
        $field = $extension->fields->firstWhere('handle', $fieldHandle);
        $field->delete();
    }

    public static function createFieldForExtension($handle, $sectionHandle, $fields) {
        $extension = \Fusion\Models\Extension::where('handle', $handle)->firstOrFail();
        $section = $extension->blueprint->sections->firstWhere('handle', $sectionHandle);
        
        foreach ($fields as $fieldData) {
            $field = $section->fields()->make($fieldData);
            
            $field->fieldable_id = $section->id;
            $field->fieldable_type = \Fusion\Models\Section::class;
            $field->save();
        }
    } 

    public static function createTaxonomy($name, $handle, $fields = []) {
        $taxonomy = Taxonomy::create([
            'name' => $name,
            'handle' => $handle,
            'slug' => $handle,
        ]);

        if (count($fields)) {
            $section = Section::create([
                'name' => 'General',
                'handle' => 'general',
                'blueprint_id' => $taxonomy->blueprint->id,
            ]);

            foreach ($fields as $fieldData) {
                $field = Field::make($fieldData);
                $field->fieldable_id = $section->id;
                $field->fieldable_type = Section::class;
                $field->save();
            }
        }
        return $taxonomy;
    }

    public static function deleteCollectionIfExists($handle) {
        $matrix = \Fusion\Models\Matrix::where('handle', $handle)->first();
        $matrix->delete();
    }

    public static function createCollection($name, $handle, $fields = []) {
        $matrix = null;
        \DB::transaction(function() use($name, $handle, $fields, &$matrix) {
            $matrix = Matrix::create([
                'name' => $name,
                'handle' => $handle,
                'slug' => $handle,
                'type' => 'collection',
            ]);

            if (count($fields)) {
                $section = Section::create([
                    'name' => 'General',
                    'handle' => 'general',
                    'blueprint_id' => $matrix->blueprint->id,
                ]);

                foreach ($fields as $fieldData) {
                    $field = Field::make($fieldData);
                    $field->fieldable_id = $section->id;
                    $field->fieldable_type = Section::class;
                    $field->save();
                }
            }
        });

        return $matrix;
    }

    public static function getMatrixId($handle)
    {
        return Cache::rememberForever('matrix['.$handle.']_id', function() use($handle) {
            return Matrix::where('handle', $handle)->value('id');
        });
    }

    public static function getTaxonomyId($handle)
    {
        return Cache::rememberForever('taxonomy['.$handle.']_id', function() use($handle) {
            return Taxonomy::where('handle', $handle)->value('id');
        });
    }
	
	public static function getTaxonomyFieldId($taxonomyHandle, $handle) {
		return Cache::rememberForever('taxanomy_field['.$taxonomyHandle.']['.$handle.']_id', function() use($taxonomyHandle, $handle) {
			$taxanomy = Taxonomy::where('handle', $taxonomyHandle)->first();
			$fields = $taxanomy->blueprint->fields->keyBy('handle');
			return $fields[$handle]->id;
		});
	}

    public static function getMatrixField($matrixHandle, $handle) {
        return Cache::rememberForever('matrix_field['.$matrixHandle.']['.$handle.']_id', function() use($matrixHandle, $handle) {
			$matrix = Matrix::where('handle', $matrixHandle)->first();
            if (!isset($matrix)) throw new \Exception('Matrix "'.$matrixHandle.'" is not found.');
            
			$fields = $matrix->blueprint->fields->keyBy('handle');
			return $fields[$handle];
		});
    }
	
	public static function getMatrixFieldId($matrixHandle, $handle) {
		return Cache::rememberForever('matrix_field['.$matrixHandle.']['.$handle.']_id', function() use($matrixHandle, $handle) {
			$matrix = Matrix::where('handle', $matrixHandle)->first();
            if (!isset($matrix)) throw new \Exception('Matrix "'.$matrixHandle.'" is not found.');
            
			$fields = $matrix->blueprint->fields->keyBy('handle');
			return $fields[$handle]->id;
		});
	}

    public static function getFieldId($fieldsetHandle, $handle)
    {
        return Cache::rememberForever('field['.$fieldsetHandle.']['.$handle.']_id', function() use($fieldsetHandle, $handle) {
            $field = static::getField($fieldsetHandle, $handle);
            return $field->id;
        });
    }

    public static function getField($fieldsetHandle, $handle)
    {
        return Cache::rememberForever('field['.$fieldsetHandle.']['.$handle.']', function() use($fieldsetHandle, $handle) {
            return FusionImporter::getField($fieldsetHandle, $handle);
        });
    }

    public static function getNewId($type, $oldId, $throwException = true)
    {
        return Cache::rememberForever('new_id['.$type.']['.$oldId.']', function() use($type, $oldId, $throwException) {
            if (!isset(static::$mappedIds[$type])) {
                static::$mappedIds[$type] = $type::get()->pluck('id', 'old_id');
            }
            if (!isset(static::$mappedIds[$type][$oldId])) {
                $newId = $type::where('old_id', $oldId)->value('id');
                static::$mappedIds[$type][$oldId] = $newId;
            }
            if (!isset(static::$mappedIds[$type][$oldId]) && $throwException) {
                throw new \Exception('Mapping ID for type: ' . $type . ' old id: ' . $oldId . ' is not found.');
            }
            return static::$mappedIds[$type][$oldId] ?? null; 
        });
    }

    public static function setModelMappedId($newModel, $oldIdAttribute = 'old_id', $newIdAttribute = 'id')
    {
        static::setMappedId(get_class($newModel), $newModel->{$oldIdAttribute}, $newModel->{$newIdAttribute});
    }

    protected static function setMappedId($type, $oldId, $newId)
    {
        static::$mappedIds[$type][$oldId] = $newId;
    }

    public static function processHtmlWithFiles($content, $directory, $throwException = true, $try = 2, $sleep = 2)
    {
        return static::saveContentFiles($content, $directory, $throwException, $try, $sleep);
    }

    /**
     * Run this method in windows repeatedly for many times may cause the following error. Please run in WSL (Windows Subsystem for Linux) if you facing the following error. 
     * curl: (77) error setting certificate verify locations:
     * CAfile: /etc/ssl/certs/ca-certificates.crt
     * CApath: none
     */
    protected static function getResponseForUrl($url)
    {
        return Cache::rememberForever('http['.$url.']', function() use($url) {
            $url = str_replace('https://', 'http://', $url);
            $response = \Illuminate\Support\Facades\Http::withOptions([
                'connect_timeout' => null,
                'timeout' => null,
            ])->timeout(5)->retry(2, 200)->get($url);

            return $response;
        });
    }

    public static function isUrlImageOrAttachment($url, $throwException = false)
    {
        try {
            $response = static::getResponseForUrl($url);
            return $response->ok() && !Str::startsWith($response->header('content-type'), 'text/');
        } catch (\Exception $ex) {
            if ($throwException) {
                throw new \Exception($url.' '.$ex->getMessage());
            }
            return false;
        }
    }

    protected static function saveContentFiles($content, $directory, $throwException, $try, $sleep)
    {
        if (!isset($content) || trim($content) == '') {
            return $content;
        }

        $crawler = new Crawler($content);
        $crawler->filter('img, a')->each(function ($element) use($sleep, $try, $throwException, $directory) {
            foreach(['src', 'href'] as $attr) {
                list($fileUrl) = $element->extract([$attr]);
                if (trim($fileUrl) != '') {
                    $fileUrl = static::getAbsoluteUrl($fileUrl);

                    try {
                        $response = static::getResponseForUrl($fileUrl);
                    } catch (\Exception $ex) {
                        if ($throwException) throw $ex;
                    }

                    if ($throwException && $response->status() == 404) {
                        abort(404, 'URL not found: '.$fileUrl);
                    } else if ($throwException && false && $response->failed()) {
                        throw new \Illuminate\Http\Client\RequestException($response);
                    }
                    
                    if (static::isUrlImageOrAttachment($fileUrl, $throwException)) {
                        FusionImporter::try($try, function () use ($element, $fileUrl, $directory, $attr) {
                            $file = static::findOrCreateFileModelIfFileExist($fileUrl, $directory);
                            if (!isset($file)) {
                                $file = static::saveUrlToFile($fileUrl, $directory);
                            }
                            $element->getNode(0)->setAttribute($attr, $file->url);
                        }, $sleep, $throwException);
                    }
                }
            }
        });

        return $crawler->filter('body')->html();
    }

    public static function getAbsoluteUrl($url)
    {
        $siteUrl = config('woocommerce.url');
        if (Str::startsWith($url, 'http://') || Str::startsWith($url, 'https://')) {
            return $url;
        }
        $prefix = trim($siteUrl, '/');
        return $prefix.'/'.trim($url, '/');
    }

    public static function getFilesFromHtml($html)
    {
        $content = [];

        $useCrawler = false;

        if ($useCrawler) {
            $crawler = new Crawler($html);
            $crawler->filter('p')->each(function ($element) use (&$content) {
                $node = $element->filter('a');
                $text = $node->count() ? $node->text() : '';

                $url = $element->filter('a')->extract(['href']);
                $url = $url[0] ?? '';

                foreach ($element->filter('a') as $node) {
                    $node->parentNode->removeChild($node);
                }
                $name = trim($element->text(), ':  ');

                if (trim($name) != '' && trim($url) != '') {
                    $content[$name] = [
                        'url' => $url,
                        'text' => $text,
                    ];
                }
            });
        } else {
            $html = str_replace(["\n", "\r"], '', $html);
            $pattern = '([^\<\>]+)<.+?href="([^\"]+?)".*?>(.+?)<\/a>';
            preg_match_all('/' . $pattern . '/i', $html, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $content[$match[1]] = [
                    'url' => $match[2],
                    'text' => $match[3],
                ];
            }
            //dd($content, $matches, $html);
        }

        $files = [];
        foreach ($content as $name => $file) {
            $filePath = Str::startsWith($file['url'], ['http', 'https']) ? $file['url'] : 'https://mycelectric.com' . $file['url'];
            $files[] = [
                'url' => $filePath,
                'title' => $name,
                'caption' => $file['text'],
                // 'width' => $fileMetaAttachmentMeta['width'] ?? null,
                // 'height' => $fileMetaAttachmentMeta['height'] ?? null,
                // 'mime' => $file->post_mime_type,
            ];
        }
        return $files;
    }

    public static function getSavedFileFirstUidByUrl($url, $directory) {
        $name = pathinfo($url, PATHINFO_FILENAME);
        $extension = pathinfo($url, PATHINFO_EXTENSION);

        return FusionImporter::getSavedFileFirstUid($name.'.'.$extension, $directory);
    }

    public static function addSavedFile($directory, $filename, $uuid)
    {
        $directoryPath = isset($directory) ? 'files/'.$directory->name : 'files';
        self::$savedFilePath[$directoryPath][$filename] = $uuid;
    }

    protected static function getLocationToBeSaved($fileUid, $name, $extension, $directory = null)
    {
        $dirPath = $directory->name ?? null;

        $filename = trim($extension) != '' ? $name.'.'.$extension : $name;
        if (isset($dirPath)) {
            $location = "files/{$dirPath}/{$fileUid}-{$filename}";
        } else {
            $location = "files/{$fileUid}-{$filename}";
        }
        return $location;
    }

    public static function findOrCreateFileModelIfFileExist($url, $directory = null, $attributes = [])
    {
        $fileUid = Fusion::getSavedFileFirstUidByUrl($url, $directory);

        if (isset($fileUid)) {
            $name = pathinfo($url, PATHINFO_FILENAME);
            $extension = pathinfo($url, PATHINFO_EXTENSION);

            return static::updateOrCreateFileModelByLocation($fileUid, $name, $extension, $directory, $attributes);
        }
    }

    protected static function updateOrCreateFileModelByLocation($fileUid, $name, $extension, $directory = null, $attributes = [])
    {
        $location = static::getLocationToBeSaved($fileUid, $name, $extension, $directory);
        $bytes = Storage::disk('public')->size($location);
        $mimetype = Storage::disk('public')->mimetype($location);
        $fullPath = Storage::disk('public')->path($location);
        $filetype = strtok($mimetype, '/');
        
        if ($filetype == 'image') {
            list($width, $height) = getimagesize($fullPath);
        }

        $disk = DB::table('disks')->where('handle', 'public')->first();

        return File::updateOrCreate([
            'directory_id' => $directory->id ?? null,
            'uuid'         => $fileUid,
            'disk_id'      => $disk->id,
        ], array_merge([
            'name'         => $name,
            'extension'    => $extension,
            'bytes'        => $bytes,
            'mimetype'     => $mimetype,
            'location'     => $location,
            'width'        => $width ?? null,
            'height'       => $height ?? null,
        ], $attributes));
    }

    public static function saveUrlToFile($url, $directory = null, $attributes = [])
    {
        if (Str::startsWith($url, '//')) {
            $url = 'http:'.$url;
        }
        $uuid = unique_id();
        $name = pathinfo($url, PATHINFO_FILENAME);
        $name = $uuid;
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        if (strpos($extension, '?') !== false || strpos($extension, '=') !== false) {
            $extension = '';
        }
        
        $location = static::getLocationToBeSaved($uuid, $name, $extension, $directory);
        $filename = trim($extension) != '' ? $name.'.'.$extension : $name;

        Storage::disk('public')->putFileAs('', $url, $location);
        static::addSavedFile($directory, $filename, $uuid);

        return static::updateOrCreateFileModelByLocation($uuid, $name, $extension, $directory, $attributes);
    }

    public static function getDirectory($path, $parent = null)
    {
        $disk = DB::table('disks')->where('handle', 'local')->first();

        return Directory::firstOrCreate([
            'name' => $path,
            'disk_id' => $disk->id,
        ], [
            'slug' => Str::slug($path),
        ]);
    }

    public static function saveFilesToModel($files, $model, Field $field, $directory = null, $throwException = true)
    {
        if (isset($files)) {
            $disk = DB::table('disks')->where('handle', 'local')->first();
            
            $directory = $directory ?? Directory::firstOrCreate([
                'name' => ($name = $field->settings['directory'] ?? 'uploads'),
                'disk_id' => $disk->id,
                'slug' => Str::slug($name),
            ]);

            $oldValues = $model->{$field->handle}->pluck('id');
            $newValues = [];
            
            foreach ($files as $key => $file) {
                if (!($file instanceof File)) {
                    try {
                        $file = static::saveUrlToFile($file['url'], $directory, [
                            'title' => $file['title'] ?? null,
                            'caption' => $file['caption'] ?? null,
                        ]);
                    } catch(\Exception $ex) {
                        if ($throwException) throw $ex;
                        return [];
                    }
                }
                
                if (!isset($newValues[$file->id])) {
                    $newValues[$file->id] = [
                        'field_id' => $field->id,
                        'order'    => $key + 1,
                    ];
                }
            }

            $model->{$field->handle}()->detach($oldValues);
            $model->{$field->handle}()->attach($newValues);
            $model->save(); // To refresh the model cache
        }
    }

    public static function getBrandImages($brand)
    {
        $assetUrlPrefix = 'https://i1.wp.com/mycelectric.com/assets-images/';
        $assetUrlPrefix = 'https://mycelectric.com/assets-images/';
        $imageAttribute = config('woocommerce.brand.image_meta_name');

        if (isset($brand->meta->{$imageAttribute})) {
            // $attachment = Attachment::query()->where('ID', $brand->meta->{$imageAttribute})->first();
            // $meta = unserialize($attachment->meta->_wp_attachment_metadata);
            // $url = $attachment->url;
            $url = $brand->meta->{$imageAttribute};

            return [
                [
                    'url' => $url,
                    // 'width' => $meta['width'] ?? null,
                    // 'height' => $meta['height'] ?? null,
                    // 'mime' => $attachment->mime_type,
                ]
            ];
        }
    }

    public static function getProductGalleryImages(WooCommerceProduct $oldProduct)
    {
        $assetUrlPrefix = 'https://i1.wp.com/mycelectric.com/assets-images/';
        $assetUrlPrefix = 'https://mycelectric.com/assets-images/';

        if (isset($oldProduct->gallery)) {
            $files = $oldProduct->gallery->map(function($attachment) use($assetUrlPrefix) {
                $meta = unserialize($attachment->meta->_wp_attachment_metadata);
                if ($meta !== false) {
                    return [
                        'url' => $assetUrlPrefix.$meta['file'],
                        'width' => $meta['width'] ?? null,
                        'height' => $meta['height'] ?? null,
                        'mime' => $attachment->mime_type,
                    ];
                }
            });
            return $files;
        }
        return collect();
    }

    /**
     * @deprecated
     */
    public static function getProductAttachments($oldProduct)
    {
        if (config('app.env') == 'local') throw new \Exception('DEPRECATED');

        $assetUrlPrefix = 'https://i1.wp.com/mycelectric.com/assets-images/';
        $assetUrlPrefix = 'https://mycelectric.com/assets-images/';
        
        $attachments = Post::type('attachment')
            ->get()->keyBy('ID');
        
        $ids = DB::connection('wordpress')->table('posts')
            ->select('ID')
            ->whereIn('post_type', ['product', 'attachment']);

        $postMeta = DB::connection('wordpress')->table('postmeta')
            ->whereIn('post_id', $ids)
            ->get()
            ->groupBy('post_id');

        $productMeta = $oldProduct->meta;

        if (isset($productMeta)) {
            $productMeta = $productMeta->keyBy('meta_key');
            $thumbnailId = isset($productMeta['_thumbnail_id']) ? $productMeta['_thumbnail_id']->meta_value : null;
            $galleryIds = isset($productMeta['_product_image_gallery']) ? $productMeta['_product_image_gallery']->meta_value : null;
            $galleryIds = isset($galleryIds) ? explode(',', $galleryIds) : [];
            array_unshift($galleryIds, $thumbnailId);

            //$this->line(print_r($productMeta, 1));

            $files = [];
            foreach ($galleryIds as $galleryId) {
                // $this->line('Thumbnail: ' . $galleryId);
                $file = $attachments[$galleryId] ?? null;

                if (isset($file)) {
                    $fileMeta = $postMeta[$file->ID]->keyBy('meta_key') ?? [];
                    $fileMetaAttachmentMeta = unserialize($fileMeta['_wp_attachment_metadata']->meta_value);

                    $filePath = $assetUrlPrefix . $fileMetaAttachmentMeta['file'];

                    $files[] = [
                        'url' => $filePath,
                        'width' => $fileMetaAttachmentMeta['width'] ?? null,
                        'height' => $fileMetaAttachmentMeta['height'] ?? null,
                        'mime' => $file->post_mime_type,
                    ];
                }
            }
            return $files;
        }
    }

    public static function getAllTabContents($oldProduct)
    {
        $tabs = static::getAllTabs($oldProduct);

    }

    public static function getReplicatorSection($replicator, $sectionHandle) {
        foreach($replicator->sections as $section) {
            if ($section->handle == $sectionHandle) {
                return $section;
            }
        }
    }

    public static function getReplicatorFieldRelationName($replicator, $section)
    {
        return "rp_{$section->handle}_{$replicator->uniqid}";
    }

    public static function getReplicator($fieldsetHandle, $handle)
    {
    	return \Fusion\Models\Replicator::findOrFail(static::getReplicatorId($fieldsetHandle, $handle));
    }

    protected static function getReplicatorId($fieldsetHandle, $handle)
    {
        $field = static::getField($fieldsetHandle, $handle);
        return $field->settings['replicator'] ?? null;
    }

    public static function getAllTabs($oldProduct)
    {
        $prefix = '_wcj_custom_product_tabs_';
        $tabs = [];
        foreach ($oldProduct->meta as $meta) {
            if (Str::startsWith($meta->meta_key, $prefix)) {
                $name = Str::between($meta->meta_key, $prefix, '_local_');
                $index = Str::afterLast($meta->meta_key, '_local_');

                // if ($name != 'content')
                $tabs[$index][$name] = $meta->meta_value;
            }
        }
        return $tabs;
    }

    public static function getTabContent($oldProduct, $name)
    {
        foreach ($oldProduct->meta as $meta) {
            if (in_array($meta->value, $name)) {
                if (!Str::startsWith($meta->meta_key, '_wcj_custom_product_tabs_title')) {
                    throw new \Exception($meta->meta_key);
                }
                $contentKey = str_replace('title', 'content', $meta->meta_key);
                return $oldProduct->meta->{$contentKey};
            }
        }
    }

    public static function updateTabContents($product, $tabs, $excludedTabs = [], $directory = null, $throwException = true)
    {
        $directory = $directory ?? Fusion::getDirectory('imported-products-details-files');
        $replicator = Fusion::getReplicator('product', 'details');
        $section = Fusion::getReplicatorSection($replicator, 'detail');
        $relation = Fusion::getReplicatorFieldRelationName($replicator, $section);

        $ids = [];
        foreach ($tabs as $tab) {
            if (isset($tab['title']) && trim($tab['title']) != '' && !in_array($tab['title'], $excludedTabs)) {
                $model = $product->{$relation}()->make([
                    'label' => $tab['title'] ?? null,
                    'content' => Fusion::processHtmlWithFiles($tab['content'] ?? null, $directory, $throwException),
                ]);
                $model->replicator_id = $replicator->id;
                $model->section_id = $section->id;
                $model->save();

                $ids[$model->id] = ['section_id' => $section->id];
            }
        }
        $oldValues = $product->{$relation}->pluck('id');
        $product->{$relation}()->detach($oldValues);
        $product->{$relation}()->attach($ids);
    }
}