<?php

namespace craftnet\controllers\api\v1;

use Craft;
use craft\i18n\Locale;
use craft\web\UploadedFile;
use craftnet\cms\CmsLicense;
use craftnet\cms\CmsLicenseManager;
use craftnet\controllers\api\BaseApiController;
use craftnet\helpers\Zendesk;
use yii\helpers\Markdown;
use yii\web\Response;

/**
 * Class SupportController
 */
class SupportController extends BaseApiController
{
    /**
     * Creates a new support request
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionCreate(): Response
    {
        $request = Craft::$app->getRequest();
        $requestHeaders = $request->getHeaders();
        $body = $request->getRequiredBodyParam('message');
        /** @var CmsLicense $cmsLicense */
        $cmsLicense = reset($this->cmsLicenses) ?: null;
        $customFields = [];

        if ($this->cmsVersion) {
            $customFields[] = [
                'id' => getenv('ZENDESK_FIELD_CRAFT_VERSION'),
                'value' => $this->cmsVersion
            ];
        }

        if (
            $this->cmsEdition &&
            in_array($this->cmsEdition, [CmsLicenseManager::EDITION_SOLO, CmsLicenseManager::EDITION_PRO], true)
        ) {
            $trial = $cmsLicense && $cmsLicense->editionHandle !== $this->cmsEdition;
            $customFields[] = [
                'id' => getenv('ZENDESK_FIELD_CRAFT_EDITION'),
                'value' => $this->cmsEdition . ($trial ? '_trial' : '')
            ];
        }

        if ($cmsLicense) {
            $customFields[] = [
                'id' => getenv('ZENDESK_FIELD_CRAFT_LICENSE'),
                'value' => $cmsLicense->key
            ];
        }

        if (!empty($this->pluginVersions)) {
            $pluginInfos = [];
            foreach ($this->pluginVersions as $pluginHandle => $pluginVersion) {
                if ($plugin = $this->plugins[$pluginHandle] ?? null) {
                    $pluginInfo = $plugin->name;
                } else {
                    $pluginInfo = $pluginHandle;
                }
                if (($edition = $this->pluginEditions[$pluginHandle] ?? null) && $edition !== 'standard') {
                    $pluginInfo .= ' ' . ucfirst($edition);
                }
                $pluginInfo .= ' ' . $pluginVersion;
                $pluginInfos[] = $pluginInfo;
            }
            $customFields[] = [
                'id' => getenv('ZENDESK_FIELD_PLUGINS'),
                'value' => implode("\n", $pluginInfos)
            ];
        }

        if (($host = $requestHeaders->get('X-Craft-Host')) !== null) {
            $customFields[] = [
                'id' => getenv('ZENDESK_FIELD_HOST'),
                'value' => $host
            ];
        }

        $client = Zendesk::client();
        $uploadTokens = [];

        $attachments = UploadedFile::getInstancesByName('attachments');
        if (empty($attachments) && $attachment = UploadedFile::getInstanceByName('attachment')) {
            $attachments = [$attachment];
        }

        if (!empty($attachments)) {
            foreach ($attachments as $i => $attachment) {
                if (!empty($attachment->tempName)) {
                    $response = $client->attachments()->upload([
                        'file' => $attachment->tempName,
                        'type' => $attachment->getMimeType(),
                        'name' => $attachment->name,
                    ]);
                    $uploadTokens[] = $response->upload->token;
                }
            }
        }

        Zendesk::client()->tickets()->create([
            'requester' => [
                'name' => $request->getRequiredBodyParam('name'),
                'email' => $request->getRequiredBodyParam('email'),
            ],
            'subject' => getenv('FRONT_SUBJECT'),
            'comment' => [
                'body' => $body,
                'html_body' => Markdown::process($body, 'gfm'),
                'uploads' => $uploadTokens,
            ],
            'type' => 'question',
            'tags' => [getenv('FRONT_TAG')],
            'custom_fields' => $customFields,
        ]);

        return $this->asJson([
            'sent' => true,
        ]);
    }
}
