<?php

declare(strict_types=1);

/*
 * This file is part of the Contao News Archiving extension.
 *
 * (c) INSPIRED MINDS
 *
 * @license LGPL-3.0-or-later
 */

namespace InspiredMinds\ContaoNewsArchiving;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsArchiveModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Executes news archiving according to each news archive's settings.
 */
#[AsCronJob('minutely')]
#[AsHook('getPageLayout')]
#[AsCallback('tl_news', 'config.onload')]
#[AsCallback('tl_news_archive', 'config.onload')]
class NewsArchiver
{
    // Whether archiving was done already in this process.
    protected static bool $archived = false;

    public function __construct(
        private readonly LoggerInterface $contaoCronLogger,
        private readonly Connection $db,
        private readonly ContaoFramework $contaoFramework,
        private readonly LockFactory $lockFactory,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(): void
    {
        // Check if archiving was done already within this process
        if (self::$archived) {
            return;
        }

        // Check if there is another archiving process already running
        $lock = $this->lockFactory->createLock(md5($this->projectDir.'-news-archiver'));

        if (!$lock->acquire()) {
            return;
        }

        try {
            $this->contaoFramework->initialize();

            // Get all news archives where archiving is active
            $archives = NewsArchiveModel::findBy(
                [
                    "archiving = '1'",
                    'archivingTarget != 0',
                    "(archivingTime != '' OR archivingStop = 1)",
                ],
                [],
                ['order' => 'title ASC'],
            );

            // Go through each archive
            foreach ($archives ?? [] as $archive) {
                // Get the target archive
                $target = NewsArchiveModel::findById($archive->archivingTarget);

                if (null === $target) {
                    continue;
                }

                $archivingTime = StringUtil::deserialize($archive->archivingTime, true);
                $value = $archivingTime['value'] ?? null;
                $unit = $archivingTime['unit'] ?? null;

                // Move according to time
                if ($value && $unit && ($time = strtotime('-'.$value.' '.$unit))) {
                    $result = $this->db->executeQuery('UPDATE tl_news SET pid = ? WHERE pid = ? AND time < ?', [$target->id, $archive->id, $time]);
                    $count = $result->rowCount();

                    if ($count > 0) {
                        $this->contaoCronLogger->info('Moved '.$count.' news entries from "'.$archive->title.'" to "'.$target->title.'" due to time criteria.');
                    }
                }

                // Move according to stop time
                if ($archive->archivingStop) {
                    $result = $this->db->executeQuery("UPDATE tl_news SET pid = ?, stop = '' WHERE pid = ? AND stop != '' AND stop < UNIX_TIMESTAMP()", [$target->id, $archive->id]);
                    $count = $result->rowCount();

                    if ($count > 0) {
                        $this->contaoCronLogger->info('Moved '.$count.' news entries from "'.$archive->title.'" to "'.$target->title.'" due to stop criteria.');
                    }
                }
            }

            // Save that archiving was executed
            self::$archived = true;
        } finally {
            $lock->release();
        }
    }
}
