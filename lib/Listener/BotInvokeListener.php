<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\C0mmandBot\Listener;

use OCA\C0mmandBot\AppInfo\Application;
use OCA\C0mmandBot\Model\Command;
use OCA\C0mmandBot\Model\CommandMapper;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener
{
    public const PARTICIPANT_TYPE_OWNER = 1;
    public const PARTICIPANT_TYPE_MODERATOR = 2;

    private const TAROT_JOIN_PHRASES = [
        "is about",
        "pertains to",
        "refers to",
        "is related to",
        "is regarding",
        "relates to",
    ];

    private const TAROT_LIGHT_PHRASES = [
        "considering",
        "exploring",
        "looking into",
        "contemplating",
        "deliberating on",
        "reflecting on",
    ];

    private const TAROT_SHADOW_PHRASES = [
        "being wary of",
        "avoiding",
        "steering clear of",
        "forgoing",
        "resisting",
        "being suspicious of",
    ];

    private const TAROT_NARRATIVE_0 = [
        "The influence that is affecting you or the matter of inquiry generally",
        "The nature of the obstacle in front of you",
        "The aim or ideal of the matter",
        "The foundation or basis of the subject that has already happened",
        "The influence that has just passed or has passed away",
        "The influence that is coming into action and will operating in the near future",
        "The position or attitude you have in the circumstances",
        "The environment or situation that have an effect on the matter",
        "The hopes or fears of the matter",
        "The culmination which is brought about by the influence shown by the other cards",
    ];

    private const TAROT_NARRATIVE_1 = [
        "The heart of the issue or influence affecting the matter of inquiry",
        "The obstacle that stands in the way",
        "Either the goal or the best potential result in the current situation",
        "The foundation of the issue which has passed into reality",
        "The past or influence that is departing",
        "The future or influence that is approaching",
        "You, either as you are, could be or are presenting yourself to be",
        "Your house or environment",
        "Your hopes and fears",
        "The ultimate result or cumulation about the influences from the other cards in the divination",
    ];

    private const TAROT_NARRATIVE_2 = [
        "Your situation",
        "An influence now coming into play",
        "Your hope or goal",
        "The issue at the root of your question",
        "An influence that will soon have an impact",
        "Your history",
        "The obstacle",
        "The possible course of action",
        "The current future if you do nothing",
        "The possible future",
    ];

    private const TAROT_NARRATIVE_3 = [
        "To resolve your situation",
        "To help clear the obstacle",
        "To help achieve your hope or goal",
        "To get at the root of your question",
        "To help see an influence that will soon have an impact",
        "To help see how you have gotten to this point",
        "To help interpret your feelings about the situation",
        "To help you understand the moods of those closest to you",
        "To help understand your fear",
        "To help see the outcome",
    ];

    public function __construct(
        protected LoggerInterface $logger,
        protected CommandMapper $mapper,
    ) {}

    public function handle(Event $event): void
    {
        if (!$event instanceof BotInvokeEvent) {
            return;
        }

        if ($event->getBotUrl() !== "nextcloudapp://" . Application::APP_ID) {
            return;
        }

        $chatMessage = $event->getMessage();
        if ($chatMessage["type"] !== "Create") {
            $this->logger->debug(
                "Not an even from creating a chat message: " .
                    $chatMessage["type"],
            );
            return;
        }

        if (!isset($chatMessage["actor"]["talkParticipantType"])) {
            $this->logger->debug("Missing participant type in data");
            return;
        }

        $isModerator = in_array(
            (int) $chatMessage["actor"]["talkParticipantType"],
            [self::PARTICIPANT_TYPE_OWNER, self::PARTICIPANT_TYPE_MODERATOR],
            true,
        );

        $content = json_decode($chatMessage["object"]["content"], true);
        if ($content["message"][0] !== "!" && $content["message"][0] !== "?") {
            return;
        }

        [$command, $message] = explode(" ", $content["message"] . " ", 2);
        if ($command === "!set") {
            if (!$isModerator) {
                // IF NOT MODERATOR, DO NOT ALLOW "!set …"
                $this->logger->debug(
                    "Can not use !set unless being a moderator",
                );
                return;
            }

            $message = trim($message);
            if (!str_contains($message, " ")) {
                $event->addReaction("👎");
                return;
            }

            [$command, $message] = explode(" ", $message, 2);
            $command = strtolower($command);
            if (
                $message === "" ||
                strlen($command) < 2 ||
                ($command[0] !== "!" && $command[0] !== "?")
            ) {
                $event->addReaction("👎");
                return;
            }

            try {
                $object = $this->mapper->getCommandForConversation(
                    $chatMessage["target"]["id"],
                    $command,
                );
            } catch (DoesNotExistException) {
                $object = new Command();
                $object->setToken($chatMessage["target"]["id"]);
                $object->setCommand($command);
            }
            $object->setMessage($message);
            if ($object->getId() !== null) {
                $this->mapper->update($object);
            } else {
                $this->mapper->insert($object);
            }
            $event->addReaction("👍");
            return;
        }
        if ($command === "!unset") {
            if (!$isModerator) {
                // IF NOT MODERATOR, DO NOT ALLOW "!set …"
                $this->logger->debug(
                    "Can not use !set unless being a moderator",
                );
                return;
            }

            $command = trim($message);
            if ($command === "") {
                $event->addReaction("👎");
                return;
            }

            try {
                $object = $this->mapper->getCommandForConversation(
                    $chatMessage["target"]["id"],
                    $command,
                );
            } catch (DoesNotExistException) {
                $event->addReaction("👎");
                return;
            }
            $this->mapper->delete($object);
            $event->addReaction("👍");
            return;
        }

        if ($command === "!command" || $command === "!commands") {
            $commands = $this->mapper->getCommandsForConversation(
                $chatMessage["target"]["id"],
            );
            if (!$isModerator && empty($commands)) {
                $event->addAnswer("*No commands configured*", true);
                return;
            }
            $response = "### 💬 Available commands" . "\n";
            $response .= "- **!command** - List all commands" . "\n";
 
            $response .=
				"- **!roll** - Roll dice in standard notation" .
                            "\n";
            $response .=
				"- **!tarot** - Draw a tarot card with a narrative interpretation" .
                "\n";
            $response .=
				"- **!ltarot** - Draw a tarot card with a longer, detailed interpretation" .
                "\n";
            $response .=
                "- **!shuffle** - Shuffle a standard 52-card playing deck for this conversation" .
                "\n";
            $response .=
                "- **!draw** - Draw the top card from the deck; automatically shuffles a fresh deck if none exists or all cards have been drawn" .
                "\n";
            $response .=
                "- **!remain** - Show how many cards are left in the current deck" .
                "\n";
            $response .= "- **!fortune** - Display a random fortune" . "\n";
            $response .=
                "- **!spelllist** - List available spell lists, or show spells for a given class (e.g. `!spelllist Druid`)" .
                "\n";
            $response .=
                "- **!spells** - Look up a spell by name or partial name (e.g. `!spells Fireball`)" .
                "\n";
            $response .=
                "- **!class** - Look up a class by name or partial name (e.g. `!class Druid`)" .
                "\n";
            $response .=
                "- **!monsters** - Look up a monster by name or partial name (e.g. `!monsters Beholder`)" .
                "\n";
            $response .=
                "- **!magicitems** - Look up a magic item by name or partial name (e.g. `!magicitems Bag of Holding`)" .
                "\n";
            $response .=
                "- **!nimble** - Look up a Nimble rule by name or partial name (e.g. `!nimble Conditions`)" .
                "\n";
            $response .=
                "- **!rules** - Look up an SRD rule by name or partial name (e.g. `!rules Combat`)" .
                "\n";
            $response .=
                "- **!!** - Browse custom content collections (e.g. `!! speeches` or `!! speeches \"Harvest Speech\"`)" .
                "\n";
            foreach ($commands as $command) {
                $response .= "- **" . $command->getCommand() . "** - ";
                $response .= $this->highlightParameters($command->getMessage());
                if ($command->getCount()) {
                    $response .=
                        " - *Current count: " . $command->getCount() . "*";
                }
                $response .= "\n";
            }
            if ($isModerator) {
                $response .= "\n";
                $response .= "---";
                $response .= "\n";
                $response .= "### ⭐ Moderators" . "\n";
                $response .= "- **!set** - Create or update a command" . "\n";
                $response .= "  ```" . "\n";
                $response .=
                    "  !set !counter The counter was used {count} times" . "\n";
                $response .= "  ```" . "\n";
                $response .= "- **!unset** - Remove a command" . "\n";
                $response .= "  ```" . "\n";
                $response .= "  !unset !counter" . "\n";
                $response .= "  ```" . "\n";
                $response .= "\n";
                $response .= "---";
                $response .= "\n";
                $response .= "### 💱 Placeholders" . "\n";
                $response .=
                    "- **{sender}** - Replaced with a mention of the sender" .
                    "\n";
                $response .=
                    "- **{mention}** - Replaced with the first mention in the command" .
                    "\n";
                $response .=
                    "- **{text}** - All text that was provided after the command" .
                    "\n";
                $response .=
                    "- **{count}** - A counter how often the command was triggered already" .
                    "\n";
            }
            $event->addAnswer($response);
            return;
        }

        if ($command === "!roll") {
            $parsedMessage = trim($message);
            $hasAdv = preg_match("/\badv\b/i", $parsedMessage) === 1;
            $hasDis = preg_match("/\bdis\b/i", $parsedMessage) === 1;
            $rollMode = null;

            if ($hasAdv xor $hasDis) {
                $rollMode = $hasAdv ? "adv" : "dis";
                $parsedMessage = trim(
                    preg_replace("/\b(adv|dis)\b/i", "", $parsedMessage),
                );
            }

            if (
                !preg_match(
                    '/^\s*(\d+)\s*[dD]\s*(\d+)(?:\s*([+-])\s*(\d+))?\s*$/',
                    $parsedMessage,
                    $matches,
                )
            ) {
                $event->addReaction("👎");
                return;
            }

            $diceCount = (int) $matches[1];
            $diceSides = (int) $matches[2];

            if ($diceCount <= 0 || $diceSides <= 0) {
                $event->addReaction("👎");
                return;
            }

            $rollOnce = function () use (
                $diceCount,
                $diceSides,
                $matches,
            ): array {
                $rolls = [];
                for ($i = 0; $i < $diceCount; $i++) {
                    $rolls[] = random_int(1, $diceSides);
                }

                $sum = array_sum($rolls);
                $total = $sum;
                $modifierValue = null;

                if (isset($matches[3]) && $matches[3] !== "") {
                    $modifierValue = (int) $matches[4];
                    if ($matches[3] === "+") {
                        $total += $modifierValue;
                    } else {
                        $total -= $modifierValue;
                    }
                }

                return [$rolls, $total, $modifierValue];
            };

            if ($rollMode === null) {
                [$rolls, $total, $modifierValue] = $rollOnce();

                $answer = "Rolled " . implode(", ", $rolls);
                if ($modifierValue !== null) {
                    $answer .= " " . $matches[3] . " (" . $modifierValue . ")";
                }
                $answer .= "\nFor a total of " . $total;
            } else {
                [$rollsA, $totalA, $modifierValueA] = $rollOnce();
                [$rollsB, $totalB, $modifierValueB] = $rollOnce();

                $answer = "Roll 1: Rolled " . implode(", ", $rollsA);
                if ($modifierValueA !== null) {
                    $answer .= " " . $matches[3] . " (" . $modifierValueA . ")";
                }
                $answer .= "\nFor a total of " . $totalA;

                $answer .= "\nRoll 2: Rolled " . implode(", ", $rollsB);
                if ($modifierValueB !== null) {
                    $answer .= " " . $matches[3] . " (" . $modifierValueB . ")";
                }
                $answer .= "\nFor a total of " . $totalB;

                if ($rollMode === "adv") {
                    $final = $totalA >= $totalB ? $totalA : $totalB;
                } else {
                    $final = $totalA <= $totalB ? $totalA : $totalB;
                }

                $answer .= "\nFINAL ROLL: " . $final;
            }

            if (
                isset($chatMessage["object"]["inReplyTo"]["actor"]["id"]) &&
                !str_starts_with(
                    $chatMessage["object"]["inReplyTo"]["actor"]["id"],
                    "bot/",
                )
            ) {
                $answer =
                    $this->getSender(
                        $chatMessage["object"]["inReplyTo"]["actor"],
                    ) .
                    " " .
                    $answer;
                $event->addAnswer(
                    $answer,
                    (int) $chatMessage["object"]["inReplyTo"]["object"]["id"],
                );
            } else {
                $event->addAnswer($answer);
            }
            return;
        }

        if ($command === "!ltarot") {
            $number = random_int(1, 156);
            $reading = $this->getLargeTarotReading($number);
            if ($reading === null) {
                $event->addReaction("👎");
                return;
            }
            $event->addAnswer($reading);
            return;
        }

        if ($command === "!tarot") {
            $number = random_int(1, 156);
            $reading = $this->getTarotReading($number);
            if ($reading === null) {
                $event->addReaction("👎");
                return;
            }
            $event->addAnswer($reading);
            return;
        }

        if ($command === "!fortune") {
            $fortunesPath = dirname(__DIR__) . "/Tarot/fortunes";
            if (!is_readable($fortunesPath)) {
                $event->addReaction("👎");
                return;
            }

            $lines = file($fortunesPath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $event->addReaction("👎");
                return;
            }

            $fortunes = array_values(
                array_filter($lines, static function (string $line): bool {
                    return !str_starts_with($line, "%") && $line !== "";
                }),
            );

            if (empty($fortunes)) {
                $event->addReaction("👎");
                return;
            }

            $fortune = $fortunes[random_int(0, count($fortunes) - 1)];
            $event->addAnswer("🔮 " . $fortune . "\n");
            return;
        }

        if ($command === "!spelllist") {
            $spellListDir = dirname(__DIR__) . "/SRD/spell_lists";
            $requestedClass = trim($message);

            if ($requestedClass === "") {
                // No argument — list available spell lists
                $files = glob($spellListDir . "/*.txt");
                if ($files === false || count($files) === 0) {
                    $event->addReaction("👎");
                    return;
                }

                $names = array_map(
                    static fn(string $f): string => "- " .
                        pathinfo($f, PATHINFO_FILENAME),
                    $files,
                );
                sort($names);

                $response = "📖 Which spell list would you like?\n";
                $response .= implode("\n", $names);
                $event->addAnswer($response);
                return;
            }

            // Argument provided — find a case-insensitive filename match
            $files = glob($spellListDir . "/*.txt");
            if ($files === false) {
                $event->addReaction("👎");
                return;
            }

            $matched = null;
            foreach ($files as $file) {
                if (
                    strcasecmp(
                        pathinfo($file, PATHINFO_FILENAME),
                        $requestedClass,
                    ) === 0
                ) {
                    $matched = $file;
                    break;
                }
            }

            if ($matched === null || !is_readable($matched)) {
                $event->addReaction("👎");
                return;
            }

            $contents = file_get_contents($matched);
            if ($contents === false) {
                $event->addReaction("👎");
                return;
            }

            $event->addAnswer($contents);
            return;
        }

        if ($command === "!spells") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/spells",
                "🧙",
                "spell",
                "spells",
                trim($message),
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!class") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/class",
                "⚔️",
                "class",
                "classes",
                trim($message),
                ".txt",
                true,
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!monsters") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/monsters",
                "🧌",
                "monster",
                "monsters",
                trim($message),
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!magicitems") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/magic_items",
                "🪄",
                "magic item",
                "magic items",
                trim($message),
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!nimble") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/nimble",
                "📑",
                "nimble rule",
                "nimble rules",
                trim($message),
                ".md",
                true,
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!rules") {
            $result = $this->lookupFromDirectory(
                dirname(__DIR__) . "/SRD/rules",
                "📜",
                "rule",
                "rules",
                trim($message),
                ".txt",
                true,
            );
            if ($result === null) {
                $event->addReaction("👎");
            } else {
                $event->addAnswer($result);
            }
            return;
        }

        if ($command === "!!") {
            $customBase = dirname(__DIR__) . "/Custom";
            $arg = trim($message);

            if ($arg === "") {
                // No argument — list all subdirectories
                $dirs = glob($customBase . "/*", GLOB_ONLYDIR);
                if ($dirs === false || empty($dirs)) {
                    $event->addReaction("👎");
                    return;
                }
                $dirNames = array_map(
                    static fn(string $d): string => "- " . basename($d),
                    $dirs,
                );
                sort($dirNames);
                $event->addAnswer(
                    "📖 Which collection would you like?\n" .
                        implode("\n", $dirNames),
                );
                return;
            }

            // Parse first argument (collection / subdirectory name)
            if ($arg[0] === '"' || $arg[0] === "'") {
                $quote = $arg[0];
                $end = strpos($arg, $quote, 1);
                if ($end !== false) {
                    $collection = substr($arg, 1, $end - 1);
                    $rest = trim(substr($arg, $end + 1));
                } else {
                    $collection = substr($arg, 1);
                    $rest = "";
                }
            } else {
                $spacePos = strpos($arg, " ");
                if ($spacePos !== false) {
                    $collection = substr($arg, 0, $spacePos);
                    $rest = trim(substr($arg, $spacePos + 1));
                } else {
                    $collection = $arg;
                    $rest = "";
                }
            }

            // Validate the collection directory exists
            $targetDir = $customBase . "/" . $collection;
            if (!is_dir($targetDir)) {
                $event->addReaction("👎");
                return;
            }

            // Collect all files in the directory (any extension)
            $allFiles = array_values(
                array_filter(
                    glob($targetDir . "/*") ?: [],
                    static fn(string $f): bool => is_file($f),
                ),
            );

            if ($rest === "") {
                // One argument — list all filenames in the collection
                if (empty($allFiles)) {
                    $event->addReaction("👎");
                    return;
                }
                $names = array_map(
                    static fn(string $f): string => "- " .
                        pathinfo($f, PATHINFO_FILENAME),
                    $allFiles,
                );
                sort($names);
                $event->addAnswer("📖 Choose from:\n" . implode("\n", $names));
                return;
            }

            // Parse second argument (search term — quoted or unquoted)
            if ($rest[0] === '"' || $rest[0] === "'") {
                $quote = $rest[0];
                $end = strpos($rest, $quote, 1);
                $search =
                    $end !== false
                        ? substr($rest, 1, $end - 1)
                        : substr($rest, 1);
                // Quoted: single substring match
                $searchTerms = [$search];
            } else {
                // Unquoted: each space-separated word is an OR search term
                $searchTerms = preg_split(
                    "/\s+/",
                    $rest,
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                );
            }

            // Match files against search terms (OR logic)
            $matches = array_values(
                array_filter($allFiles, static function (string $f) use (
                    $searchTerms,
                ): bool {
                    $name = pathinfo($f, PATHINFO_FILENAME);
                    foreach ($searchTerms as $term) {
                        if (stripos($name, $term) !== false) {
                            return true;
                        }
                    }
                    return false;
                }),
            );

            if (empty($matches)) {
                $event->addReaction("👎");
                return;
            }

            if (count($matches) === 1) {
                $contents = file_get_contents($matches[0]);
                if ($contents === false) {
                    $event->addReaction("👎");
                    return;
                }
                $event->addAnswer($contents);
                return;
            }

            // Multiple matches — offer as a choice
            $names = array_map(
                static fn(string $f): string => "- " .
                    pathinfo($f, PATHINFO_FILENAME),
                $matches,
            );
            sort($names);
            $event->addAnswer(
                "📖 Choose from these:\n" . implode("\n", $names),
            );
            return;
        }

        if ($command === "!shuffle") {
            $token = $chatMessage["target"]["id"];
            $cards = $this->shuffleAndStoreDeck($token);
            if ($cards === null) {
                $event->addReaction("👎");
                return;
            }

            $event->addAnswer(
                "🃏 The deck has been shuffled. " .
                    count($cards) .
                    " cards remain.",
            );
            return;
        }

        if ($command === "!draw") {
            $token = $chatMessage["target"]["id"];
            $autoShuffled = false;

            try {
                $deck = $this->mapper->getCommandForConversation(
                    $token,
                    "!deck",
                );
                $cards = json_decode($deck->getMessage(), true);
                if (!is_array($cards) || count($cards) === 0) {
                    $cards = $this->shuffleAndStoreDeck($token);
                    $autoShuffled = true;
                }
            } catch (DoesNotExistException) {
                $cards = $this->shuffleAndStoreDeck($token);
                $autoShuffled = true;
            }

            if ($cards === null) {
                $event->addReaction("👎");
                return;
            }

            $drawn = array_shift($cards);

            // Re-fetch the entity only when shuffleAndStoreDeck created or
            // replaced it; otherwise $deck is already the correct entity.
            if ($autoShuffled || !isset($deck)) {
                try {
                    $deck = $this->mapper->getCommandForConversation(
                        $token,
                        "!deck",
                    );
                } catch (DoesNotExistException) {
                    $deck = new Command();
                    $deck->setToken($token);
                    $deck->setCommand("!deck");
                }
            }
            $deck->setMessage(json_encode($cards));
            if ($deck->getId() !== null) {
                $this->mapper->update($deck);
            } else {
                $this->mapper->insert($deck);
            }

            $answer = "";
            if ($autoShuffled) {
                $answer .=
                    "🃏 The deck has been shuffled. Drawing first card...\n";
            }
            $answer .= "🃏 " . $drawn;
            $event->addAnswer($answer);
            return;
        }

        if ($command === "!remain") {
            $token = $chatMessage["target"]["id"];
            try {
                $deck = $this->mapper->getCommandForConversation(
                    $token,
                    "!deck",
                );
                $cards = json_decode($deck->getMessage(), true);
                $count = is_array($cards) ? count($cards) : 0;
            } catch (DoesNotExistException) {
                $count = 0;
            }

            $event->addAnswer(
                "🃏 " .
                    $count .
                    " card" .
                    ($count === 1 ? "" : "s") .
                    " remain" .
                    ($count === 1 ? "s" : "") .
                    " in the deck.",
            );
            return;
        }

        try {
            $object = $this->mapper->getCommandForConversation(
                $chatMessage["target"]["id"],
                $command,
            );
        } catch (DoesNotExistException) {
            $event->addReaction("👎");
            return;
        }

        $string = $object->getMessage();

        $searches = $replacements = [];
        if (str_contains($string, "{mention}")) {
            $mention = $this->getFirstMentionId($content["parameters"]);
            if ($mention === null) {
                return;
            }

            $searches[] = "{mention}";
            $replacements[] = $mention;
        }

        if (str_contains($string, "{text}")) {
            $searches[] = "{text}";
            $replacements[] = $this->getText($message, $content["parameters"]);
        }

        if (str_contains($string, "{sender}")) {
            $searches[] = "{sender}";
            $replacements[] = $this->getSender($chatMessage["actor"]);
        }

        if (str_contains($string, "{count}")) {
            $this->mapper->increaseCount($object);
            $searches[] = "{count}";
            $replacements[] = (string) $object->getCount();
        }

        $answer = str_replace($searches, $replacements, $string);

        if ($answer !== "") {
            if (
                isset($chatMessage["object"]["inReplyTo"]["actor"]["id"]) &&
                !str_starts_with(
                    $chatMessage["object"]["inReplyTo"]["actor"]["id"],
                    "bot/",
                )
            ) {
                $answer =
                    $this->getSender(
                        $chatMessage["object"]["inReplyTo"]["actor"],
                    ) .
                    " " .
                    $answer;
                $event->addAnswer(
                    $answer,
                    (int) $chatMessage["object"]["inReplyTo"]["object"]["id"],
                );
            } else {
                $event->addAnswer($answer);
            }
        }
    }

    /**
     * Generic directory-based lookup used by !class, !monsters, !magicitems, etc.
     *
     * @param string $dir          Absolute path to the directory of content files
     * @param string $icon         Emoji prefix for prompt/list responses
     * @param string $itemLabel    Singular label used in the no-argument prompt (e.g. "monster")
     * @param string $itemsLabel   Plural label used in the multiple-match response (e.g. "monsters")
     * @param string $arg          The trimmed argument the user supplied (empty string = no arg)
     * @param string $extension    File extension to glob for, including the dot (default: ".txt")
     * @param bool   $listAllOnEmpty  When true, return all filenames instead of a prompt when no arg is given
     * @return string|null         The answer string, or null when a 👎 reaction should be sent instead
     */
    protected function lookupFromDirectory(
        string $dir,
        string $icon,
        string $itemLabel,
        string $itemsLabel,
        string $arg,
        string $extension = ".txt",
        bool $listAllOnEmpty = false,
    ): ?string {
        // No argument
        if ($arg === "") {
            if (!$listAllOnEmpty) {
                return $icon .
                    "Please give at least the first letter of the " .
                    $itemLabel .
                    ".";
            }

            $files = glob($dir . "/*" . $extension);
            if ($files === false || empty($files)) {
                return null;
            }
            $names = array_map(
                static fn(string $f): string => pathinfo($f, PATHINFO_FILENAME),
                $files,
            );
            sort($names);
            return implode(
                "\n",
                array_map(static fn(string $n): string => "- " . $n, $names),
            );
        }

        $files = glob($dir . "/*" . $extension);
        if ($files === false) {
            return null;
        }

        $names = array_map(
            static fn(string $f): string => pathinfo($f, PATHINFO_FILENAME),
            $files,
        );

        // 1. Exact case-insensitive match
        foreach ($names as $name) {
            if (strcasecmp($name, $arg) === 0) {
                $contents = file_get_contents($dir . "/" . $name . $extension);
                return $contents !== false ? $contents : null;
            }
        }

        // 2. Single a-z character — return filenames beginning with that letter
        if (preg_match('/^[a-z]$/i', $arg)) {
            $filtered = array_values(
                array_filter(
                    $names,
                    static fn(string $n): bool => stripos($n, $arg) === 0,
                ),
            );
            sort($filtered);
            if (empty($filtered)) {
                return null;
            }
            return implode(
                "\n",
                array_map(static fn(string $n): string => "- " . $n, $filtered),
            );
        }

        // 3. Partial case-insensitive match
        $matches = array_values(
            array_filter(
                $names,
                static fn(string $n): bool => stripos($n, $arg) !== false,
            ),
        );

        if (count($matches) === 1) {
            $contents = file_get_contents(
                $dir . "/" . $matches[0] . $extension,
            );
            return $contents !== false ? $contents : null;
        }

        if (count($matches) > 1) {
            sort($matches);
            $response = $icon . "Choose from these " . $itemsLabel . ":\n";
            $response .= implode(
                "\n",
                array_map(static fn(string $n): string => "- " . $n, $matches),
            );
            return $response;
        }

        // No match
        return null;
    }

    protected function getTarotReading(int $number): ?string
    {
        $cardData = $this->getTarotCardData($number);
        if ($cardData === null) {
            return null;
        }

        [$cardName, $orientation] = $cardData;

        $meaning = $this->getTarotMeaning($cardName, $orientation);
        if ($meaning === null) {
            return null;
        }

        $position = 0;
        $preface = $this->getTarotNarrative($position);
        $joiner =
            self::TAROT_JOIN_PHRASES[
                random_int(0, count(self::TAROT_JOIN_PHRASES) - 1)
            ];
        if ($orientation === "shadow") {
            $join2 =
                self::TAROT_SHADOW_PHRASES[
                    random_int(0, count(self::TAROT_SHADOW_PHRASES) - 1)
                ];
        } else {
            $join2 =
                self::TAROT_LIGHT_PHRASES[
                    random_int(0, count(self::TAROT_LIGHT_PHRASES) - 1)
                ];
        }

        return "#" .
            $cardName .
            " in " .
            $orientation .
            ": " .
            $preface .
            " " .
            $joiner .
            " " .
            $join2 .
            " " .
            $meaning;
    }

    protected function getTarotNarrative(int $position): string
    {
        $chooser = random_int(0, 3);
        $source = match ($chooser) {
            0 => self::TAROT_NARRATIVE_0,
            1 => self::TAROT_NARRATIVE_1,
            2 => self::TAROT_NARRATIVE_2,
            default => self::TAROT_NARRATIVE_3,
        };

        if (!isset($source[$position])) {
            return self::TAROT_NARRATIVE_0[0];
        }

        return $source[$position];
    }

    protected function getLargeTarotReading(int $number): ?string
    {
        $orientation = $number > 78 ? "shadow" : "light";
        $cardNumber = $number > 78 ? $number - 78 : $number;

        $cardData = $this->getTarotCardData($cardNumber);
        if ($cardData === null) {
            return null;
        }

        [$cardName] = $cardData;

        $interpretationsPath =
            dirname(__DIR__) . "/Tarot/interpretations_large.json";
        if (!is_readable($interpretationsPath)) {
            return null;
        }

        $data = json_decode(
            (string) file_get_contents($interpretationsPath),
            true,
        );
        if (!is_array($data) || !isset($data["tarot_interpretations"])) {
            return null;
        }

        foreach ($data["tarot_interpretations"] as $entry) {
            if (!isset($entry["name"]) || $entry["name"] !== $cardName) {
                continue;
            }

            $meanings = $entry["meanings"][$orientation] ?? null;
            if (!is_array($meanings) || $meanings === []) {
                return null;
            }

            return (string) $meanings[0];
        }

        return null;
    }

    /**
     * Reads playing_cards.dat, shuffles all 52 card values, persists the
     * result to the database for the given conversation token, and returns
     * the shuffled array. Returns null if the data file cannot be read.
     *
     * @return list<string>|null
     */
    protected function shuffleAndStoreDeck(string $token): ?array
    {
        $datFile = __DIR__ . "/../Tarot/playing_cards.dat";
        $lines = file($datFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $cards = [];
        foreach ($lines as $line) {
            $pos = strpos($line, "=");
            if ($pos !== false) {
                $cards[] = substr($line, $pos + 1);
            }
        }

        shuffle($cards);

        try {
            $deck = $this->mapper->getCommandForConversation($token, "!deck");
        } catch (DoesNotExistException) {
            $deck = new Command();
            $deck->setToken($token);
            $deck->setCommand("!deck");
        }
        $deck->setMessage(json_encode($cards));
        if ($deck->getId() !== null) {
            $this->mapper->update($deck);
        } else {
            $this->mapper->insert($deck);
        }

        return $cards;
    }

    protected function getTarotCardData(int $number): ?array
    {
        $cardsPath = dirname(__DIR__) . "/Tarot/number_cards.dat";
        if (!is_readable($cardsPath)) {
            return null;
        }

        foreach (
            file($cardsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            as $line
        ) {
            if (!str_starts_with($line, (string) $number . "=")) {
                continue;
            }

            $parts = explode("=", $line, 3);
            if (count($parts) < 3) {
                return null;
            }

            $cardName = trim($parts[1]);
            $orientation = trim($parts[2]);

            if ($cardName === "" || $orientation === "") {
                return null;
            }

            return [$cardName, $orientation];
        }

        return null;
    }

    protected function getTarotMeaning(
        string $cardName,
        string $orientation,
    ): ?string {
        $interpretationsPath = dirname(__DIR__) . "/Tarot/interpretations.json";
        if (!is_readable($interpretationsPath)) {
            return null;
        }

        $data = json_decode(
            (string) file_get_contents($interpretationsPath),
            true,
        );
        if (!is_array($data) || !isset($data["tarot_interpretations"])) {
            return null;
        }

        foreach ($data["tarot_interpretations"] as $entry) {
            if (!isset($entry["name"]) || $entry["name"] !== $cardName) {
                continue;
            }

            $meanings = $entry["meanings"][$orientation] ?? null;
            if (!is_array($meanings) || $meanings === []) {
                return null;
            }

            $index = random_int(0, count($meanings) - 1);
            return strtolower((string) $meanings[$index]);
        }

        return null;
    }

    protected function highlightParameters(string $message): string
    {
        return str_replace(
            ["{count}", "{mention}", "{sender}", "{text}"],
            ["*{count}*", "*{mention}*", "*{sender}*", "*{text}*"],
            $message,
        );
    }

    protected function getSender(array $actor): string
    {
        [$type, $id] = explode("/", $actor["id"], 2);
        $type = rtrim($type, "s");
        // TODO check with federated users and guests
        if ($type === "user") {
            return '@"' . $id . '"';
        }
        return '@"' . $type . "/" . $id . '"';
    }

    protected function getFirstMentionId(array $parameters): ?string
    {
        foreach ($parameters as $parameter) {
            $replace = $this->getMentionReplacement($parameter);
            if ($replace !== null) {
                return $replace;
            }
        }

        return null;
    }

    protected function getText(string $message, array $parameters): ?string
    {
        $search = $replacements = [];
        foreach ($parameters as $key => $parameter) {
            $replace = $this->getMentionReplacement($parameter);
            $search[] = "{" . $key . "}";
            $replacements[] = $replace ?? $parameter["name"];
        }

        return str_replace($search, $replacements, $message);
    }

    protected function getMentionReplacement(array $parameter): ?string
    {
        return match ($parameter["type"]) {
            "call" => "@all",
            "user" => isset($parameter["server"])
                ? '@"federated_user/' .
                    $parameter["id"] .
                    "@" .
                    $parameter["server"] .
                    '"'
                : '@"' . $parameter["id"] . '"',
            "user-group" => '@"group/' . $parameter["id"] . '"',
            "guest" => '@"guest/' . $parameter["id"] . '"',
            default => null,
        };
    }
}
