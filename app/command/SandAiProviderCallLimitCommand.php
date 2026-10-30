<?php

declare(strict_types=1);

namespace app\command;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderCallLimitGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use think\facade\Db;

#[AsCommand(name: 'sand-ai:provider-call-limit', description: '配置专用调用方的材料模型调用总次数窗口；不签发权限或凭据')]
final class SandAiProviderCallLimitCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'create、status（只读）或 close（永久关闭）');
        foreach (['code', 'organization-id', 'application-id', 'environment-id', 'workload-client-id', 'max-calls', 'expires-at'] as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $started = microtime(true);
        try {
            $action = $input->getArgument('action');
            $code = $input->getOption('code');
            if (!is_string($code) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $code) !== 1
                || !in_array($action, ['create', 'status', 'close'], true)) {
                throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_INPUT_INVALID', '请指定 create、status 或 close 与 --code（1至64位字母、数字、下划线、连字符）');
            }
            if ($action === 'create') {
                $output->writeln('步骤1：核对专用调用方的可信归属、窗口和既有模型派发；不修改IAM或金额预算');
                $scope = [];
                foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                    $scope[$field] = $this->positiveInteger($input->getOption(str_replace('_', '-', $field)));
                }
                $maxCalls = $this->positiveInteger($input->getOption('max-calls'));
                $expiry = $input->getOption('expires-at');
                $date = is_string($expiry) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expiry, new \DateTimeZone('UTC')) : false;
                if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d\TH:i:s\Z') !== $expiry
                    || $date->getTimestamp() <= time() || $date->getTimestamp() > time() + 7200) {
                    throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_INPUT_INVALID', '--expires-at 必须是未来两小时内的UTC时间，例如 2026-10-13T12:00:00Z；实际使用时填写当前授权截止时间');
                }
                $this->create($code, $scope, $maxCalls, $date->format('Y-m-d H:i:s'));
                $output->writeln('步骤2：次数池及调用方绑定已原子保存；既有同参数绑定只读复用，不能重置或续期');
            } elseif ($action === 'close') {
                $output->writeln('步骤1：永久关闭该次数窗口；保留绑定与已领取次数，不解除调用方限制');
                $pool = Db::connect('pgsql')->table('sand_ai_provider_call_limit_pool')->where('code', $code)->find();
                if ($pool === null) {
                    throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_NOT_FOUND', '未找到指定次数窗口');
                }
                Db::connect('pgsql')->table('sand_ai_provider_call_limit_pool')->where('id', (int) $pool['id'])
                    ->where('state', 'active')->update(['state' => 'closed', 'update_time' => gmdate('Y-m-d H:i:s')]);
            } else {
                $output->writeln('步骤1：只读查询窗口和已领取次数');
            }
            $pool = Db::connect('pgsql')->table('sand_ai_provider_call_limit_pool')->where('code', $code)->find();
            if ($pool === null) {
                throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_NOT_FOUND', '未找到指定次数窗口');
            }
            $used = Db::connect('pgsql')->table('sand_ai_provider_call_limit_claim')->where('pool_id', (int) $pool['id'])->count();
            $output->writeln('状态：' . $pool['state'] . '；已领取/上限：' . $used . '/' . $pool['max_calls'] . '；UTC截止：' . $pool['expire_time']);
            $output->writeln('领取后即使未发送也不退次数；未知结果冻结窗口；不代表真实模型或业务链已验收');
            $output->writeln(sprintf('成功，耗时 %.3f 秒', microtime(true) - $started));
            return Command::SUCCESS;
        } catch (ApiProblem $problem) {
            $output->writeln($problem->errorCode . '：' . $problem->getMessage());
        } catch (\Throwable) {
            $output->writeln('SAND_AI_PROVIDER_CALL_LIMIT_UNAVAILABLE：操作未能确认成功；请检查迁移状态，再用原code只读status核对，不更换调用方或窗口绕过');
        }
        $output->writeln(sprintf('失败，耗时 %.3f 秒', microtime(true) - $started));
        return Command::FAILURE;
    }

    private function positiveInteger(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_INPUT_INVALID', '归属ID和次数须为1至999999999999999999的整数');
        }
        return (int) $value;
    }

    /** @param array<string,int> $scope */
    private function create(string $code, array $scope, int $maxCalls, string $expiry): void
    {
        Db::connect('pgsql')->transaction(function () use ($code, $scope, $maxCalls, $expiry): void {
            ProviderCallLimitGuard::lockCaller($scope['workload_client_id']);
            $db = Db::connect('pgsql');
            $existing = $db->table('sand_ai_provider_call_limit_binding')
                ->where('workload_client_id', $scope['workload_client_id'])->find();
            if ($existing !== null) {
                $pool = $db->table('sand_ai_provider_call_limit_pool')->where('id', (int) $existing['pool_id'])->find();
                foreach ($scope as $field => $value) {
                    if ((int) $existing[$field] !== $value) {
                        throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_CONFLICT', '已有绑定归属不同；不能更换或删除原绑定');
                    }
                }
                if ($pool === null || $pool['code'] !== $code || (int) $pool['max_calls'] !== $maxCalls || $pool['expire_time'] !== $expiry) {
                    throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_CONFLICT', '已有次数池不同；不能换池、重置次数或延长窗口');
                }
                return;
            }
            // First binding is for a fresh dedicated caller. The same advisory
            // lock at dispatch claim excludes an unbound send racing creation.
            if ($db->table('sand_ai_invocation')->where('workload_client_id', $scope['workload_client_id'])->find() !== null
                || $db->table('sand_ai_provider_call_limit_pool')->where('code', $code)->find() !== null) {
                throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_CONFLICT', '首次绑定必须在专用调用方尚无模型调用记录时完成，且窗口code不可复用');
            }
            $now = gmdate('Y-m-d H:i:s');
            $poolId = $db->table('sand_ai_provider_call_limit_pool')->insertGetId([
                'code' => $code, 'max_calls' => $maxCalls, 'state' => 'active',
                'start_time' => $now, 'expire_time' => $expiry, 'create_time' => $now, 'update_time' => $now,
            ]);
            $db->table('sand_ai_provider_call_limit_binding')->insert($scope + ['pool_id' => $poolId, 'create_time' => $now]);
        });
    }
}
