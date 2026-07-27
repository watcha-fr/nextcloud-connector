<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Watcha <contact@watcha.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Watcha\Command;

use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Replays share acceptance over the existing estate.
 *
 * The structural fix (the UserAddedEvent listener and the room member sync
 * endpoint) only repairs rooms as they are used. This command exists to catch
 * up members who joined before the fix was deployed.
 *
 * Safe by default: without `--force` in a non-interactive shell the command
 * refuses to write, so it cannot mutate an estate by accident — for example
 * from a cron job or a copy-pasted one-liner.
 */
class AcceptPendingShares extends Command {

    public function __construct(
        private ShareAcceptanceService $shareAcceptanceService,
        private IGroupManager $groupManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName("watcha:shares:accept-pending")
            ->setDescription("Accept the pending document shares of Watcha room folders for their current members")
            ->addOption(
                "room",
                null,
                InputOption::VALUE_REQUIRED,
                "Restrict the operation to a single Matrix room id (e.g. '!abc:example.org')"
            )
            ->addOption(
                "dry-run",
                null,
                InputOption::VALUE_NONE,
                "Report what would be accepted without changing anything"
            )
            ->addOption(
                "force",
                null,
                InputOption::VALUE_NONE,
                "Apply the changes without asking for confirmation (required in non-interactive shells)"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dryRun = (bool)$input->getOption("dry-run");
        $force = (bool)$input->getOption("force");
        $room = $input->getOption("room");

        $groupIds = $this->resolveGroupIds(is_string($room) ? $room : null, $output);
        if ($groupIds === null) {
            return 1;
        }
        if ($groupIds === []) {
            $output->writeln("<info>No Watcha room group holds a document share; nothing to do.</info>");
            return 0;
        }

        if (!$dryRun && !$this->confirm($input, $output, count($groupIds), $force)) {
            return 1;
        }

        $mode = $dryRun ? "DRY RUN" : "APPLY";
        $output->writeln("<info>Mode: $mode — " . count($groupIds) . " room group(s) to inspect</info>");
        $output->writeln("");

        $roomsTouched = 0;
        $pendingFound = 0;
        $accepted = 0;
        $failed = 0;

        foreach ($groupIds as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                $output->writeln("<comment>$groupId: group is referenced by a share but does not exist — skipped</comment>");
                $failed++;
                continue;
            }

            $roomPending = [];
            foreach ($group->getUsers() as $user) {
                $uid = $user->getUID();
                foreach ($this->shareAcceptanceService->inspectSharesForUser($uid, $groupId) as $share) {
                    if ($share["status"] !== "accepted") {
                        $roomPending[] = [$uid, $share["shareId"], $share["status"]];
                    }
                }
            }

            if ($roomPending === []) {
                if ($output->isVerbose()) {
                    $output->writeln("$groupId: nothing pending");
                }
                continue;
            }

            $roomsTouched++;
            $pendingFound += count($roomPending);
            $output->writeln("<info>$groupId</info>: " . count($roomPending) . " pending (member, share) couple(s)");
            foreach ($roomPending as [$uid, $shareId, $status]) {
                $output->writeln("    - user=$uid share=$shareId status=$status");
            }

            if ($dryRun) {
                continue;
            }

            $acceptedHere = $this->shareAcceptanceService->acceptPendingSharesForGroup($groupId);
            $accepted += $acceptedHere;
            $stillPending = count($roomPending) - $acceptedHere;
            if ($stillPending > 0) {
                // Not silent: the operator must know which rooms need a second
                // pass. Details are in nextcloud.log with app=watcha.
                $failed += $stillPending;
                $output->writeln("  <comment>=> accepted $acceptedHere, still pending $stillPending (see nextcloud.log)</comment>");
            } else {
                $output->writeln("  <info>=> accepted $acceptedHere</info>");
            }
        }

        $output->writeln("");
        $output->writeln("<info>Summary</info>");
        $output->writeln("  room groups inspected : " . count($groupIds));
        $output->writeln("  room groups affected  : $roomsTouched");
        $output->writeln("  pending couples found : $pendingFound");
        if ($dryRun) {
            $output->writeln("  <comment>dry run: nothing was changed. Re-run with --force to apply.</comment>");
            return 0;
        }
        $output->writeln("  shares accepted       : $accepted");
        $output->writeln("  still pending         : $failed");

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @return string[]|null the group ids to process, or null on a usage error
     */
    private function resolveGroupIds(?string $room, OutputInterface $output): ?array {
        if ($room === null) {
            return $this->shareAcceptanceService->findRoomGroupsWithShares();
        }

        $groupId = $this->shareAcceptanceService->findRoomGroupId($room);
        if ($groupId === null) {
            $output->writeln("<error>No Nextcloud group found for room $room</error>");
            return null;
        }

        return [$groupId];
    }

    private function confirm(InputInterface $input, OutputInterface $output, int $groupCount, bool $force): bool {
        if ($force) {
            return true;
        }

        if (!$input->isInteractive()) {
            $output->writeln("<error>Refusing to modify shares in a non-interactive shell.</error>");
            $output->writeln("Re-run with <info>--dry-run</info> to simulate, or <info>--force</info> to apply.");
            return false;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper("question");
        $question = new ConfirmationQuestion(
            "About to accept pending shares on $groupCount room group(s). Continue? [y/N] ",
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln("Aborted.");
            return false;
        }

        return true;
    }
}
