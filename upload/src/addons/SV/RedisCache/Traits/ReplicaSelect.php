<?php

namespace SV\RedisCache\Traits;

trait ReplicaSelect
{
    protected function replicaOptions(array &$options)
    {
        // normalize old options to newer ones
        $options['sentinel_primary'] = $options['sentinel_primary'] ?? $options['sentinel_master'] ?? $options['sentinel_master_set'] ?? $options['sentinel_primary_set'] ?? null;
        unset($options['sentinel_master'], $options['sentinel_master_set'], $options['sentinel_primary_set']);
        $options['sentinel_primary_verify'] = $options['sentinel_primary_verify'] ?? $options['sentinel_master_verify'] ?? null;
        unset($options['sentinel_master_verify']);
        $options['primary_write_only'] = $options['primary_write_only'] ?? $options['master_write_only'] ?? null;
        unset($options['master_write_only']);
        $options['retry_reads_on_primary'] = $options['retry_reads_on_primary'] ?? $options['retry_reads_on_master'] ?? false;
        unset($options['retry_reads_on_master']);

        $options['load_from_replica'] = $options['load_from_replica'] ?? $options['load_from_slave'] ?? null;
        unset($options['load_from_slave']);
        $options['load_from_replicas'] = $options['load_from_replicas'] ?? $options['load_from_slaves'] ?? null;
        unset($options['load_from_slaves']);
        $options['replica_select_callable'] = $options['replica_select_callable'] ?? $options['replica-select'] ?? $options['slave_select_callable'] ?? $options['slave-select'] ?? 'preferLocalReplica';
        unset($options['replica-select'], $options['slave_select_callable'], $options['slave-select']);

        // if it is a string, assume it is some method on this class
        $replicaSelect = $options['replica_select_callable'] ?? null;
        if (\is_string($replicaSelect))
        {
            $options['replica_select_callable'] = [$this, $replicaSelect];
        }

        // stock phpredis connector compatibility
        if (!empty($options['host']))
        {
            $options['server'] = $options['host'];
        }
        if (empty($options['server']))
        {
            $options['server'] = 'localhost';
        }
    }

    protected function getLocalIps(array $ips = null): array
    {
        if (!\is_array($ips))
        {
            // I can't believe there isn't a better way
            try
            {
                $output = \shell_exec('hostname --all-ip-addresses');
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output)
            {
                $ips = \array_fill_keys(\array_filter(\array_map('\trim', \explode(' ', $output))), true);
            }
        }

        return $ips ?: [];
    }

    /**
     * @param array<string,string> $ips
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     * @noinspection PhpUnusedParameterInspection
     */
    protected function selectLocalRedis(array $ips, array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        if ($ips)
        {
            foreach ($replicas as $replica)
            {
                // replica host is just an ip
                $host = $replica->getHost();
                if (isset($ips[$host]))
                {
                    return $replica;
                }
            }
        }

        $replicaKey = \array_rand($replicas);

        return $replicas[$replicaKey];
    }

    /**
     * @deprecated
     */
    public function preferLocalSlave(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplica($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param  \Credis_Client  $primary
     * @return \Credis_Client|null
     */
    public function preferLocalReplica(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $ips = $this->getLocalIps();

        return $this->selectLocalRedis($ips, $replicas, $primary);
    }

    /**
     * @deprecated
     */
    public function preferLocalSlaveLocalDisk(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplicaLocalDisk($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     */
    protected function preferLocalReplicaLocalDisk(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $output = @\file_get_contents('/tmp/local_ips');
        if ($output === false)
        {
            try
            {
                $output = \shell_exec('hostname --all-ip-addresses');
            }
            catch (\Exception $e)
            {
                $output = '';
            }
            if ($output !== false)
            {
                \file_put_contents('/tmp/local_ips', $output);
            }
        }

        $ips = null;
        if ($output)
        {
            $ips = \array_fill_keys(\array_filter(\array_map('\trim', \explode(' ', $output))), true);
        }

        return $this->selectLocalRedis($ips ?: [], $replicas, $primary);
    }

    /**
     * @deprecated
     */
    public function preferLocalSlaveAPCu(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        return $this->preferLocalReplicaAPCu($replicas, $primary);
    }

    /**
     * @param \Credis_Client[] $replicas
     * @param \Credis_Client   $primary
     * @return \Credis_Client|null
     */
    public function preferLocalReplicaAPCu(array $replicas, \Credis_Client $primary): ?\Credis_Client
    {
        $ips = null;
        if (\function_exists('apcu_fetch'))
        {
            $ips = \apcu_fetch('localips', $hasIps);
        }
        if (!\is_array($ips))
        {
            $ips = $this->getLocalIps();
            if (\function_exists('apcu_store'))
            {
                // bit racing on the first connection, but local IPs rarely change.
                \apcu_store('localips', $ips);
            }
        }

        return $this->selectLocalRedis($ips ?: [], $replicas, $primary);
    }


    public function getCredis(bool $allowReplica = false): \Credis_Client
    {
        if ($allowReplica && $this->_replica !== null)
        {
            return $this->_replica;
        }

        return $this->_redis;
    }

    /**
     * @return ?\Credis_Client
     * @deprecated
     */
    public function getSlaveCredis(): ?\Credis_Client
    {
        return $this->getReplicaCredis();
    }

    /**
     * @deprecated
     * @param \Credis_Client|null $replica
     * @return void
     */
    public function setSlaveCredis(?\Credis_Client $replica): void
    {
        $this->_replica = $replica;
    }

    public function getReplicaCredis(): ?\Credis_Client
    {
        return $this->_replica;
    }

    public function setReplicaCredis(?\Credis_Client $replica): void
    {
        $this->_replica = $replica;
    }

}