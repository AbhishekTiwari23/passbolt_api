<?php
declare(strict_types=1);

/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         2.0.0
 */
namespace App\Model\Table;

use App\Model\Entity\Avatar;
use App\Utility\AvatarProcessing;
use App\Utility\Filesystem\FilesystemTrait;
use App\View\Helper\AvatarHelper;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Log\Log;
use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Laminas\Diactoros\Stream;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * @property \App\Model\Table\ProfilesTable&\Cake\ORM\Association\BelongsTo $Profiles
 * @method \App\Model\Entity\Avatar newEmptyEntity()
 * @method \App\Model\Entity\Avatar newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Avatar[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Avatar get($primaryKey, $options = [])
 * @method \App\Model\Entity\Avatar findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\Avatar patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Avatar[] patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Avatar|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Avatar saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\Avatar[]|\Cake\Datasource\ResultSetInterface|false saveMany(iterable $entities, $options = [])
 * @method \App\Model\Entity\Avatar[]|\Cake\Datasource\ResultSetInterface saveManyOrFail(iterable $entities, $options = [])
 * @method \App\Model\Entity\Avatar[]|\Cake\Datasource\ResultSetInterface|false deleteMany(iterable $entities, $options = [])
 * @method \App\Model\Entity\Avatar[]|\Cake\Datasource\ResultSetInterface deleteManyOrFail(iterable $entities, $options = [])
 * @method \Cake\ORM\Query findById(string $id)
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AvatarsTable extends Table
{
    use FilesystemTrait;

    public const FORMAT_SMALL = 'small';
    public const FORMAT_MEDIUM = 'medium';
    public const MAX_SIZE = '5MB';
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif'];
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'gif'];

    /**
     * @var string
     */
    protected $cacheDirectory;

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->addBehavior('Timestamp');
        $this->belongsTo('Profiles');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('file', __('A file is required.'))
            ->allowEmptyString('file', __('The file should not be empty'), false)
            ->add('file', 'validMimeType', [
                'rule' => ['mimeType', self::ALLOWED_MIME_TYPES],
                'message' => __(
                    'The file mime type should be one of the following: {0}.',
                    implode(', ', self::ALLOWED_MIME_TYPES)
                ),
            ])
            ->add('file', 'validExtension', [
                'rule' => ['extension', self::ALLOWED_EXTENSIONS],
                'message' => __(
                    'The file extension should be one of the following: {0}.',
                    implode(', ', self::ALLOWED_EXTENSIONS)
                ),
            ])
            ->add('file', 'validUploadedFile', [
                'rule' => ['uploadedFile', ['maxSize' => self::MAX_SIZE]], // Max size in bytes
                'message' => __(
                    'The file is not valid, or exceeds max size of {0} bytes.',
                    self::MAX_SIZE
                ),
            ]);

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['profile_id'], 'Profiles'));

        // Add a rule checking that either the url or some image data are set

        return $rules;
    }

    /**
     * Implements beforeSave() callback.
     * Convert in resized jpeg format.
     *
     * @param \Cake\Event\Event $event the event
     * @param \App\Model\Entity\Avatar $avatar entity
     * @return void
     */
    public function beforeSave(Event $event, Avatar $avatar)
    {
        if (!$this->setData($avatar)) {
            $avatar->setError('data', __('Could not save the data in {0} format.', AvatarHelper::IMAGE_EXTENSION));
            $event->stopPropagation();
        }
    }

    /**
     * Implements afterSave() callback.
     * Mainly used to delete former versions of avatars.
     * Store the avatar in the cache.
     *
     * @param \Cake\Event\Event $event the event
     * @param \App\Model\Entity\Avatar $avatar entity
     * @param \ArrayObject $options options
     * @return void
     */
    public function afterSave(Event $event, Avatar $avatar, \ArrayObject $options)
    {
        $this->storeInCache($avatar);

        $this->deleteMany($this->find()->where([
            $this->aliasField('profile_id') => $avatar->get('profile_id'),
            $this->aliasField('id') . ' <>' => $avatar->get('id'),
        ]));
    }

    /**
     * After an avatar was deleted, its caching directory gets deleted as well.
     *
     * @param \Cake\Event\Event $event the event
     * @param \App\Model\Entity\Avatar $avatar entity
     * @param \ArrayObject $options options
     * @return void
     */
    public function afterDelete(Event $event, Avatar $avatar, \ArrayObject $options)
    {
        try {
            $this->getFilesystem()->deleteDirectory($avatar->get('id'));
        } catch (FilesystemException $exception) {
            Log::warning($exception->getMessage());
        }
    }

    /**
     * Formatter for Avatar entities.
     * Used mainly to populate an avatar when no there is no result with the default avatar url.
     *
     * @param \Cake\Collection\CollectionInterface $avatars list of avatars (\App\Model\Entity\Avatar)
     * @return mixed
     */
    public static function formatResults(CollectionInterface $avatars)
    {
        return $avatars->map(function ($avatar) {
            if (empty($avatar)) {
                // If avatar is empty, we instantiate one.
                // The virtual field will take care of retrieving the default avatar.
                $avatar = new Avatar();
            }

            return $avatar;
        });
    }

    /**
     * Generate an Avatar contain clause to be inserted in a contain table.
     *
     * @return array
     */
    public static function addContainAvatar(): array
    {
        return [
            'Avatars' => function (Query $q) {
                // Formatter for empty avatars.
                return $q->formatResults(function (CollectionInterface $avatars) {
                    return AvatarsTable::formatResults($avatars);
                });
            },
        ];
    }

    /**
     * @param string|null $id Avatar id
     * @param string $format Avaar format
     * @return \Laminas\Diactoros\Stream
     */
    public function readSteamFromId(?string $id, string $format): Stream
    {
        /** @var \App\Model\Entity\Avatar|null $avatar */
        $avatar = $id ? $this->findById($id)->first() : null;

        if (is_null($avatar)) {
            return new Stream($this->getFallBackFileName($format));
        } else {
            $format = trim($format, AvatarHelper::IMAGE_EXTENSION);

            return $this->readStreamInCache($avatar, $format);
        }
    }

    /**
     * Returns the full path to the file in cache.
     * If the cache does not exist, tries to create it.
     * If no data is in the avatar, returns the default
     * avatar image.
     *
     * @param \App\Model\Entity\Avatar $avatar The avatar concerned.
     * @param string $format The format to recover.
     * @return \Laminas\Diactoros\Stream The full path to the filename.
     */
    public function readStreamInCache(Avatar $avatar, string $format = self::FORMAT_SMALL): Stream
    {
        $fileName = $this->getAvatarFileName($avatar, $format);

        if (!$this->getFilesystem()->fileExists($fileName)) {
            try {
                $this->storeInCache($avatar);
                $stream = $this->getFilesystem()->readStream($fileName);
            } catch (\Throwable $exception) {
                Log::warning(__('Could not save the avatar in cache.'));
                $stream = $this->getFallBackFileName($format);
            }
        } else {
            try {
                $stream = $this->getFilesystem()->readStream($fileName);
            } catch (\Throwable $exception) {
                Log::warning(__('Could not read the avatar in cache.'));
                $stream = $this->getFallBackFileName($format);
            }
        }

        return new Stream($stream);
    }

    /**
     * Store the image in $avatar->data in medium and small formats.
     *
     * @param \App\Model\Entity\Avatar $avatar Avatar to read the data from.
     * @return void
     */
    public function storeInCache(Avatar $avatar): void
    {
        if (empty($avatar->get('data'))) {
            return;
        }

        $data = $avatar->get('data');
        
        // TODO: get rid of this crap
//        if (is_resource($data)) {
//            $data = stream_get_contents($data);
//        }

        try {
            $this->getFilesystem()->write($this->getMediumAvatarFileName($avatar), $data);
        } catch (\Throwable $e) {
            Log::error('Error while saving medium avatar with ID {0}', $avatar->get('id'));
            Log::error($e->getMessage());
        }

        try {
            $content = $this->getFilesystem()->read($this->getMediumAvatarFileName($avatar));
            $smallImage = AvatarProcessing::resizeAndCrop(
                $content,
                Configure::readOrFail('FileStorage.imageSizes.Avatar.small.thumbnail.width'),
                Configure::readOrFail('FileStorage.imageSizes.Avatar.small.thumbnail.height'),
            );
            $this->getFilesystem()->write($this->getSmallAvatarFileName($avatar), $smallImage);
        } catch (\Throwable $e) {
            Log::error('Error while saving small avatar with ID {0}', $avatar->get('id'));
            Log::error($e->getMessage());
        }
    }

    /**
     * Parse the file provided, resize it and store it in the
     * data property of the avatar.
     *
     * @param \App\Model\Entity\Avatar $avatar Avatar concerned.
     * @return bool
     */
    public function setData(Avatar $avatar): bool
    {
        $file = $avatar->get('file');

        if ($file === null) {
            // If the avatar provided is empty, the avatar will not be saved, but we should not
            // block the saving. See UsersTable::editEntity() where an empty Avatar is set per default.
            return true;
        } elseif (is_array($file)) {
            $content = file_get_contents($file['tmp_name']);
        } elseif ($file instanceof UploadedFileInterface) {
            $content = $file->getStream()->getContents();
        } else {
            return false;
        }

        try {
            $img = AvatarProcessing::resizeAndCrop(
                $content,
                Configure::readOrFail('FileStorage.imageSizes.Avatar.medium.thumbnail.width'),
                Configure::readOrFail('FileStorage.imageSizes.Avatar.medium.thumbnail.height')
            );
            $avatar->set('data', $img);
        } catch (\Exception $exception) {
            return false;
        }

        return true;
    }

    /**
     * Get or create the relative directory name of a given avatar.
     *
     * @param \App\Model\Entity\Avatar $avatar Avatar
     * @return string
     * @throws \League\Flysystem\FilesystemException The cache directory must be readable/writable.
     */
    public function getOrCreateAvatarDirectory(Avatar $avatar): string
    {
        $avatarCacheSubDirectory = $avatar->get('id') . DS;
        $this->getFilesystem()->createDirectory($avatarCacheSubDirectory);

        return $avatarCacheSubDirectory;
    }

    /**
     * @param \App\Model\Entity\Avatar $avatar Avatar.
     * @param string|null $format Format.
     * @return string
     */
    public function getAvatarFileName(Avatar $avatar, ?string $format = null): string
    {
        if (empty($avatar->get('data'))) {
            return $this->getFallBackFileName($format);
        } elseif ($format === self::FORMAT_SMALL) {
            return $this->getSmallAvatarFileName($avatar);
        } else {
            return $this->getMediumAvatarFileName($avatar);
        }
    }

    /**
     * @param \App\Model\Entity\Avatar $avatar Avatar.
     * @return string
     */
    public function getSmallAvatarFileName(Avatar $avatar): string
    {
        return $this->getOrCreateAvatarDirectory($avatar) . self::FORMAT_SMALL . AvatarHelper::IMAGE_EXTENSION;
    }

    /**
     * @param \App\Model\Entity\Avatar $avatar Avatar concerned
     * @return string
     */
    public function getMediumAvatarFileName(Avatar $avatar): string
    {
        return $this->getOrCreateAvatarDirectory($avatar) . self::FORMAT_MEDIUM . AvatarHelper::IMAGE_EXTENSION;
    }

    /**
     * The default avatar file.
     *
     * @param ?string $format Format of the image.
     * @return string
     * @throws \RuntimeException if the avatar config is not set in config/file_storage.php
     */
    public function getFallBackFileName(?string $format = null): string
    {
        if (empty($format)) {
            $format = $this->getDefaultFormat();
        }
        try {
            $fileName = Configure::readOrFail('FileStorage.imageDefaults.Avatar.' . $format);
        } catch (\RuntimeException $e) {
            $fileName = Configure::readOrFail('FileStorage.imageDefaults.Avatar.' . $this->getDefaultFormat());
        }

        return WWW_ROOT . $fileName;
    }

    /**
     * The default avatar format
     *
     * @return string
     */
    public function getDefaultFormat(): string
    {
        return self::FORMAT_MEDIUM;
    }
}
