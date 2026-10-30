<?php

declare(strict_types=1);

namespace app\command;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\InternalSecurityInitialization;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sand-ai:security', description: '检查或首次初始化 SandAI 内部安全材料，不更换已有材料')]
final class SandAiSecurityCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'status、status-tools（只读）、protect-config、initialize 或 initialize-tools（各类别首次初始化）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        if (!is_string($action) || !in_array($action, ['status', 'status-tools', 'protect-config', 'initialize', 'initialize-tools'], true)) {
            $output->writeln('仅支持 status、status-tools、protect-config、initialize 或 initialize-tools；在已安装宿主的 server 目录运行。');
            return Command::INVALID;
        }
        try {
            if ($action === 'status') {
                $status = InternalSecurityInitialization::status();
                $output->writeln($status['ready'] ? '内部安全材料状态：ready（不表示模型或整产品验收通过）' : '内部安全材料状态：not_ready');
                foreach ($status['missing_groups'] as $group) {
                    $output->writeln('缺项类别：' . $group);
                }
                foreach ($status['invalid_groups'] as $group) {
                    $output->writeln('需恢复类别：' . $group);
                }
                if (!$status['ready']) {
                    $output->writeln('首次且无业务数据时由宿主操作员运行 initialize；已有数据或不完整材料请恢复该实例原备份。');
                }
                $tools = InternalSecurityInitialization::toolStatus();
                $output->writeln($tools['ready'] ? '外部工具安全材料状态：ready' : '外部工具安全材料状态：not_ready（单独运行 status-tools 核对；不影响以上四类判定）');
                return $status['ready'] ? Command::SUCCESS : Command::FAILURE;
            }
            if ($action === 'status-tools') {
                $status = InternalSecurityInitialization::toolStatus();
                $output->writeln($status['ready'] ? '外部工具安全材料状态：ready（不表示 MCP 或搜索已验收）' : '外部工具安全材料状态：not_ready');
                foreach ($status['missing_groups'] as $group) $output->writeln('缺项类别：' . $group);
                foreach ($status['invalid_groups'] as $group) $output->writeln('需恢复类别：' . $group);
                if (!$status['ready']) $output->writeln('首次且无工具连接/运行/回执历史时运行 initialize-tools；已有或残缺材料请恢复本实例原备份。');
                return $status['ready'] ? Command::SUCCESS : Command::FAILURE;
            }
            if ($action === 'initialize-tools') {
                $output->writeln('步骤1：核对工具材料、私有配置和工具连接/运行/回执历史；保留其它四类材料');
                $result = (new InternalSecurityInitialization())->initializeTools();
                $output->writeln($result['changed'] ? '步骤2：工具连接加密与幂等材料已私有保存；其它配置原字节保持' : '外部工具材料已 ready，未写入任何配置');
                $output->writeln($result['changed'] ? '用新 CLI 进程运行 status-tools；由宿主操作员正式 reload 后再联测。' : '可继续外部工具配置；端点、权限、来源与计量仍需真实验证。');
                return Command::SUCCESS;
            }
            if ($action === 'protect-config') {
                $output->writeln('步骤1：核对当前操作员所有权、普通文件及配置原字节');
                $changed = (new InternalSecurityInitialization())->protectConfiguration();
                $output->writeln($changed ? '步骤2：配置权限已收紧为0600；原内容保持，私有原配置备份已保存' : '配置已为0600，原内容保持');
                $output->writeln('接着运行 initialize；本动作没有生成、更换内部材料或重启服务。');
                return Command::SUCCESS;
            }
            $output->writeln('步骤1：核对已有材料、配置权限及首次业务数据保护');
            $result = (new InternalSecurityInitialization())->initialize();
            $output->writeln($result['changed'] ? '步骤2：缺失内部材料已私有保存；已有配置保持原字节' : '内部安全材料已 ready，未写入任何配置');
            $output->writeln($result['changed'] ? '请先用新CLI进程运行 status；由宿主操作员通过正式生命周期reload服务，再配置模型。' : '可继续配置模型；模型、IAM授权和业务链仍需实际验证。');
            return Command::SUCCESS;
        } catch (ApiProblem $problem) {
            $output->writeln($problem->errorCode . '：' . $problem->getMessage());
        } catch (\Throwable) {
            $output->writeln('SAND_AI_SECURITY_INITIALIZATION_FAILED：请先检查状态与原配置备份；不要输出内部材料或反复生成。');
        }
        return Command::FAILURE;
    }
}
