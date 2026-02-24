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
